<?php

namespace App\Tests\Service;

use App\Entity\DailyMetric;
use App\Entity\User;
use App\Entity\Video;
use App\Repository\DailyMetricRepository;
use App\Repository\DemographicSnapshotRepository;
use App\Repository\ReportingJobRepository;
use App\Repository\VideoRepository;
use App\Service\GoogleAuthService;
use App\Service\YouTubeReportingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class YouTubeReportingServiceTest extends TestCase
{
    public function testReachReportCreatesMissingDailyMetricWithCtrAndImpressions(): void
    {
        $user = new User();
        $video = (new Video())
            ->setUser($user)
            ->setYoutubeId('video-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (DailyMetric $metric) use ($video): bool {
                return $metric->getVideo() === $video
                    && $metric->getDate()->format('Y-m-d') === '2026-05-28'
                    && $metric->getViews() === 0
                    && $metric->getImpressions() === 1200
                    && $metric->getCtr() === 4.2;
            }));

        $dailyMetricRepo = $this->createMock(DailyMetricRepository::class);
        $dailyMetricRepo->method('findOneBy')->willReturn(null);
        $dailyMetricRepo->expects($this->once())->method('invalidateListStats')->with($user);

        $videoRepo = $this->createMock(VideoRepository::class);
        $videoRepo->expects($this->once())->method('findByYoutubeId')->with('video-1')->willReturn($video);

        $service = $this->makeService($em, $dailyMetricRepo, $videoRepo);

        $count = $this->invokePrivate($service, 'processReachRows', [[[
            'date' => '2026-05-28',
            'video_id' => 'video-1',
            'video_thumbnail_impressions' => '1200',
            'video_thumbnail_impressions_ctr' => '0.042',
        ]], $user]);

        $this->assertSame(1, $count);
    }

    public function testTrafficReportCreatesMissingDailyMetricAndWritesSources(): void
    {
        $user = new User();
        $video = (new Video())
            ->setUser($user)
            ->setYoutubeId('video-1');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (DailyMetric $metric) use ($video): bool {
                return $metric->getVideo() === $video
                    && $metric->getDate()->format('Y-m-d') === '2026-05-28'
                    && $metric->getViews() === 15
                    && $metric->getTrafficSources() === [
                        'YT_SEARCH' => 12,
                        'EXT_URL' => 3,
                    ];
            }));

        $dailyMetricRepo = $this->createMock(DailyMetricRepository::class);
        $dailyMetricRepo->method('findOneBy')->willReturn(null);
        $dailyMetricRepo->expects($this->once())->method('invalidateListStats')->with($user);

        $videoRepo = $this->createMock(VideoRepository::class);
        $videoRepo->expects($this->once())->method('findByYoutubeId')->with('video-1')->willReturn($video);

        $service = $this->makeService($em, $dailyMetricRepo, $videoRepo);

        $count = $this->invokePrivate($service, 'processTrafficSourceRows', [[
            ['date' => '2026-05-28', 'video_id' => 'video-1', 'traffic_source_type' => 'YT_SEARCH', 'views' => '12'],
            ['date' => '2026-05-28', 'video_id' => 'video-1', 'traffic_source_type' => 'EXT_URL', 'views' => '3'],
        ], $user]);

        $this->assertSame(1, $count);
    }

    public function testTrafficReportBackfillsViewsOnExistingZeroMetric(): void
    {
        $user = new User();
        $video = (new Video())
            ->setUser($user)
            ->setYoutubeId('video-1');
        $metric = (new DailyMetric())
            ->setVideo($video)
            ->setDate(new \DateTimeImmutable('2026-05-28'))
            ->setViews(0)
            ->setImpressions(1200)
            ->setCtr(4.2);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('persist')
            ->with($this->callback(function (DailyMetric $persisted) use ($metric): bool {
                return $persisted === $metric
                    && $persisted->getViews() === 15
                    && $persisted->getImpressions() === 1200
                    && $persisted->getCtr() === 4.2
                    && $persisted->getTrafficSources() === [
                        'YT_SEARCH' => 12,
                        'EXT_URL' => 3,
                    ];
            }));

        $dailyMetricRepo = $this->createMock(DailyMetricRepository::class);
        $dailyMetricRepo->method('findOneBy')->willReturn($metric);
        $dailyMetricRepo->expects($this->once())->method('invalidateListStats')->with($user);

        $videoRepo = $this->createMock(VideoRepository::class);
        $videoRepo->expects($this->once())->method('findByYoutubeId')->with('video-1')->willReturn($video);

        $service = $this->makeService($em, $dailyMetricRepo, $videoRepo);

        $count = $this->invokePrivate($service, 'processTrafficSourceRows', [[
            ['date' => '2026-05-28', 'video_id' => 'video-1', 'traffic_source_type' => 'YT_SEARCH', 'views' => '12'],
            ['date' => '2026-05-28', 'video_id' => 'video-1', 'traffic_source_type' => 'EXT_URL', 'views' => '3'],
        ], $user]);

        $this->assertSame(1, $count);
    }

    private function makeService(
        EntityManagerInterface $em,
        DailyMetricRepository $dailyMetricRepo,
        VideoRepository $videoRepo,
    ): YouTubeReportingService {
        return new YouTubeReportingService(
            $this->createMock(GoogleAuthService::class),
            $em,
            $this->createMock(ReportingJobRepository::class),
            $dailyMetricRepo,
            $this->createMock(DemographicSnapshotRepository::class),
            $videoRepo,
            new NullLogger(),
        );
    }

    private function invokePrivate(object $object, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($object, $method);

        return $ref->invokeArgs($object, $args);
    }
}
