<?php

namespace App\Service;

use App\Entity\DemographicSnapshot;
use App\Entity\ReportingJob;
use App\Entity\User;
use App\Repository\DailyMetricRepository;
use App\Repository\DemographicSnapshotRepository;
use App\Repository\ReportingJobRepository;
use App\Repository\VideoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Google\Client as GoogleClient;
use Google\Service\YouTubeReporting;
use Google\Service\YouTubeReporting\Job;
use Psr\Log\LoggerInterface;

class YouTubeReportingService
{
    /** Report types we create jobs for and process. */
    private const REPORT_TYPES = [
        'channel_reach_basic_a1',    // impressions + CTR
        'channel_demographics_a1',   // age/gender breakdown
        'channel_traffic_source_a3', // historical traffic sources
    ];

    public function __construct(
        private readonly GoogleAuthService $authService,
        private readonly EntityManagerInterface $em,
        private readonly ReportingJobRepository $jobRepo,
        private readonly DailyMetricRepository $dailyMetricRepo,
        private readonly DemographicSnapshotRepository $demoRepo,
        private readonly VideoRepository $videoRepo,
        private readonly LoggerInterface $logger,
    ) {}

    public function syncForUser(User $user): array
    {
        $client = $this->authService->getAuthenticatedClientForUser($user);
        if (!$client) {
            throw new \RuntimeException('Utilisateur non authentifié avec Google.');
        }

        $reporting = new YouTubeReporting($client);
        $this->ensureJobs($reporting, $user);

        $counts = [
            'impressions_ctr' => 0,
            'demographics'    => 0,
            'traffic_sources' => 0,
        ];

        foreach ($this->jobRepo->findAllForUser($user) as $job) {
            $newReports = $this->fetchNewReports($reporting, $job);
            if (empty($newReports)) continue;

            foreach ($newReports as $report) {
                $csv = $this->downloadCsv($client, $report->getDownloadUrl());
                if (empty($csv)) continue;

                $count = match ($job->getReportTypeId()) {
                    'channel_reach_basic_a1'    => $this->processReachRows($csv, $user),
                    'channel_demographics_a1'   => $this->processDemographicsRows($csv, $user),
                    'channel_traffic_source_a3' => $this->processTrafficSourceRows($csv, $user),
                    default                     => 0,
                };

                $this->em->flush();

                $counts[match ($job->getReportTypeId()) {
                    'channel_reach_basic_a1'    => 'impressions_ctr',
                    'channel_demographics_a1'   => 'demographics',
                    'channel_traffic_source_a3' => 'traffic_sources',
                    default                     => 'impressions_ctr',
                }] += $count;

                // Advance the cursor so we don't re-process this report
                $reportStart = new \DateTimeImmutable($report->getStartTime());
                if (!$job->getLastProcessedAt() || $reportStart > $job->getLastProcessedAt()) {
                    $job->setLastProcessedAt($reportStart);
                }
            }

            $this->em->flush();
        }

        return $counts;
    }

    // ─── Job management ──────────────────────────────────────────────────────

    private function ensureJobs(YouTubeReporting $reporting, User $user): void
    {
        foreach (self::REPORT_TYPES as $typeId) {
            if ($this->jobRepo->findForUserAndType($user, $typeId)) continue;

            try {
                $apiJob = new Job();
                $apiJob->setReportTypeId($typeId);
                $apiJob->setName('youtube_analyse_' . $typeId);
                $created = $reporting->jobs->create($apiJob);

                $entity = (new ReportingJob())
                    ->setUser($user)
                    ->setReportTypeId($typeId)
                    ->setGoogleJobId($created->getId());

                $this->em->persist($entity);
                $this->logger->info('Reporting API job created', ['type' => $typeId, 'job_id' => $created->getId()]);

            } catch (\Exception $e) {
                $this->logger->warning('Failed to create reporting job', [
                    'type'  => $typeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->em->flush();
    }

    /** Returns reports that have not been processed yet for this job. */
    private function fetchNewReports(YouTubeReporting $reporting, ReportingJob $job): array
    {
        try {
            $params = [];
            if ($since = $job->getLastProcessedAt()) {
                // startTimeAtOrAfter expects RFC3339
                $params['startTimeAtOrAfter'] = $since->format(\DateTimeInterface::RFC3339);
            }

            $response = $reporting->jobs_reports->listJobsReports($job->getGoogleJobId(), $params);
            return $response->getReports() ?? [];

        } catch (\Exception $e) {
            $this->logger->warning('Failed to list reports', [
                'job_id' => $job->getGoogleJobId(),
                'error'  => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ─── CSV download ────────────────────────────────────────────────────────

    private function downloadCsv(GoogleClient $client, string $url): array
    {
        try {
            $httpClient = $client->authorize();
            $response   = $httpClient->get($url);
            $content    = (string) $response->getBody();
            return $this->parseCsv($content);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to download report CSV', ['error' => $e->getMessage()]);
            return [];
        }
    }

    private function parseCsv(string $content): array
    {
        $lines = array_filter(explode("\n", trim($content)));
        if (count($lines) < 2) return [];

        $headers = str_getcsv(array_shift($lines));
        $rows    = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line);
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }
        return $rows;
    }

    // ─── Reach (CTR + impressions) ───────────────────────────────────────────

    /**
     * channel_reach_basic_a1 columns:
     * date, channel_id, video_id, video_thumbnail_impressions, video_thumbnail_impressions_ctr
     */
    private function processReachRows(array $rows, User $user): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $video = $this->videoRepo->findByYoutubeId($row['video_id'] ?? '');
            if (!$video || $video->getUser()->getId() !== $user->getId()) continue;

            $date   = \DateTimeImmutable::createFromFormat('Y-m-d', $row['date'] ?? '');
            $metric = $date ? $this->dailyMetricRepo->findOneBy(['video' => $video, 'date' => $date->setTime(0, 0, 0)]) : null;
            if (!$metric) continue;

            $impressions = isset($row['video_thumbnail_impressions']) ? (int) $row['video_thumbnail_impressions'] : null;
            $ctr         = isset($row['video_thumbnail_impressions_ctr']) && $row['video_thumbnail_impressions_ctr'] !== ''
                ? (float) $row['video_thumbnail_impressions_ctr'] * 100 // API returns 0–1, store as %
                : null;

            if ($impressions !== null) $metric->setImpressions($impressions);
            if ($ctr !== null)         $metric->setCtr($ctr);

            $count++;
        }
        return $count;
    }

    // ─── Demographics ────────────────────────────────────────────────────────

    /**
     * channel_demographics_a1 columns:
     * date, channel_id, video_id, age_group, gender, views_percentage
     */
    private function processDemographicsRows(array $rows, User $user): int
    {
        $count = 0;
        foreach ($rows as $row) {
            $video = $this->videoRepo->findByYoutubeId($row['video_id'] ?? '');
            if (!$video || $video->getUser()->getId() !== $user->getId()) continue;

            $date = \DateTimeImmutable::createFromFormat('Y-m-d', $row['date'] ?? '');
            if (!$date) continue;

            $ageGroup  = $row['age_group'] ?? '';
            $gender    = $row['gender'] ?? '';
            $viewsPct  = isset($row['views_percentage']) ? (float) $row['views_percentage'] * 100 : 0.0;

            if (!$ageGroup || !$gender) continue;

            $snapshot = $this->demoRepo->findOneBy([
                'video'    => $video,
                'date'     => $date->setTime(0, 0, 0),
                'ageGroup' => $ageGroup,
                'gender'   => $gender,
            ]) ?? (new DemographicSnapshot())
                ->setVideo($video)
                ->setDate($date->setTime(0, 0, 0))
                ->setAgeGroup($ageGroup)
                ->setGender($gender);

            $snapshot->setViewsPercentage($viewsPct);
            $this->em->persist($snapshot);
            $count++;
        }
        return $count;
    }

    // ─── Traffic sources (historical backfill) ───────────────────────────────

    /**
     * channel_traffic_source_a3 columns:
     * date, channel_id, video_id, traffic_source_type, traffic_source_detail, views, watch_time_minutes
     */
    private function processTrafficSourceRows(array $rows, User $user): int
    {
        // Aggregate by (video_id, date) first to build the full map per day
        $byVideoDate = [];
        foreach ($rows as $row) {
            $ytId = $row['video_id'] ?? '';
            $date = $row['date']     ?? '';
            if (!$ytId || !$date) continue;

            $key    = $ytId . '|' . $date;
            $source = $row['traffic_source_type'] ?? 'UNKNOWN';
            $views  = (int) ($row['views'] ?? 0);

            $byVideoDate[$key]['ytId']   = $ytId;
            $byVideoDate[$key]['date']   = $date;
            $byVideoDate[$key]['sources'][$source] = ($byVideoDate[$key]['sources'][$source] ?? 0) + $views;
        }

        $count = 0;
        foreach ($byVideoDate as ['ytId' => $ytId, 'date' => $dateStr, 'sources' => $sources]) {
            $video = $this->videoRepo->findByYoutubeId($ytId);
            if (!$video || $video->getUser()->getId() !== $user->getId()) continue;

            $date   = \DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
            $metric = $date ? $this->dailyMetricRepo->findOneBy(['video' => $video, 'date' => $date->setTime(0, 0, 0)]) : null;
            if (!$metric) continue;

            // Only backfill if no traffic data yet (Analytics API may have already filled recent days)
            if ($metric->getTrafficSources() === null) {
                $metric->setTrafficSources($sources);
                $count++;
            }
        }
        return $count;
    }
}
