<?php

namespace App\Tests\Service;

use App\Entity\AiReport;
use App\Entity\Video;
use App\Enum\AiReportStatus;
use App\Enum\AiReportType;
use App\Repository\AiReportRepository;
use App\Repository\AppSettingRepository;
use App\Repository\CommentRepository;
use App\Repository\DailyMetricRepository;
use App\Repository\VideoRepository;
use App\Repository\VideoSearchTermRepository;
use App\Service\AiAnalysisService;
use App\Service\AiProviderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AiAnalysisServiceTest extends TestCase
{
    private function makeService(
        array $videos = [],
        ?AiReport $existingReport = null,
        ?array $claudePayload = [],
    ): AiAnalysisService {
        $em = $this->createMock(EntityManagerInterface::class);

        $anthropic = $this->createMock(AiProviderInterface::class);
        $anthropic->method('loadPrompt')->willReturn('test prompt');
        $anthropic->method('call')->willReturnCallback(function (AiReport $report, string $prompt) use ($claudePayload) {
            if ($claudePayload !== null) {
                $report->setPayload($claudePayload);
                $report->setStatus(AiReportStatus::Done);
            } else {
                $report->setStatus(AiReportStatus::Failed);
            }
            return $claudePayload;
        });

        $aiReportRepo = $this->createMock(AiReportRepository::class);
        $aiReportRepo->method('findRecentDone')->willReturn($existingReport);

        $videoRepo = $this->createMock(VideoRepository::class);
        $videoRepo->method('findForUser')->willReturn($videos);
        $videoRepo->method('findRecentForUser')->willReturn($videos);
        $videoRepo->method('findTopPerformingForUser')->willReturn([]);

        $metricRepo  = $this->createMock(DailyMetricRepository::class);
        $metricRepo->method('findLatestForVideo')->willReturn(null);
        $metricRepo->method('findForVideo')->willReturn([]);
        $metricRepo->method('getEarlyViewsBaseline')->willReturn([]);

        $commentRepo = $this->createMock(CommentRepository::class);
        $commentRepo->method('countNewSinceLastAnalysis')->willReturn(0);

        $searchTermRepo = $this->createMock(VideoSearchTermRepository::class);
        $searchTermRepo->method('findTopForVideo')->willReturn([]);

        $settingRepo = $this->createMock(AppSettingRepository::class);
        $settingRepo->method('get')->willReturn(null);

        return new AiAnalysisService($anthropic, $em, $aiReportRepo, $videoRepo, $metricRepo, $commentRepo, $searchTermRepo, $settingRepo, new NullLogger());
    }

    public function testAnalyzeAllReturnsZeroWithNoVideos(): void
    {
        $user = $this->createMock(\App\Entity\User::class);
        $result = $this->makeService([])->analyzeAll($user);
        $this->assertSame(0, array_sum($result['counts']));
    }

    public function testUploadScheduleSkipsWhenRecentReportExists(): void
    {
        $existing = new AiReport();
        $existing->setType(AiReportType::UploadSchedule);
        $existing->setStatus(AiReportStatus::Done);

        $user = $this->createMock(\App\Entity\User::class);

        $aiReportRepo = $this->createMock(AiReportRepository::class);
        $aiReportRepo->expects($this->once())
            ->method('findRecentDone')
            ->with(null, AiReportType::UploadSchedule)
            ->willReturn($existing);

        $em          = $this->createMock(EntityManagerInterface::class);
        $anthropic = $this->createMock(AiProviderInterface::class);
        $anthropic->expects($this->never())->method('call');

        $videoRepo   = $this->createMock(VideoRepository::class);
        $videoRepo->method('findRecentForUser')->willReturn([new Video()]);

        $service = new AiAnalysisService(
            $anthropic, $em, $aiReportRepo,
            $videoRepo,
            $this->createMock(DailyMetricRepository::class),
            $this->createMock(CommentRepository::class),
            $this->createMock(VideoSearchTermRepository::class),
            $this->createMock(AppSettingRepository::class),
            new NullLogger(),
        );

        $count = $service->runUploadSchedule($user);
        $this->assertSame(0, $count);
    }
}
