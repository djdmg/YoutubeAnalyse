<?php
namespace App\Enum;

enum AiReportType: string
{
    case TitleOptimization = 'title_optimization';
    case CommentAnalysis   = 'comment_analysis';
    case Anomaly           = 'anomaly';
    case Prediction        = 'prediction';
    case UploadSchedule    = 'upload_schedule';
    case SeoOptimization         = 'seo_optimization';
    case ThumbnailAnalysis       = 'thumbnail_analysis';
    case DescriptionOptimization = 'description_optimization';
    case ThumbnailGeneration     = 'thumbnail_generation';
    case ThumbnailPrompt         = 'thumbnail_prompt';
    case GoalSuggestions         = 'goal_suggestions';

    public function label(): string
    {
        return match($this) {
            self::TitleOptimization      => 'Optimisation titre',
            self::CommentAnalysis        => 'Analyse commentaires',
            self::Anomaly                => 'Anomalie détectée',
            self::Prediction             => 'Prédiction J+30',
            self::UploadSchedule         => 'Stratégie publication',
            self::SeoOptimization        => 'SEO — Requêtes de recherche',
            self::ThumbnailAnalysis      => 'Analyse miniature',
            self::DescriptionOptimization => 'Optimisation description',
            self::ThumbnailGeneration    => 'Génération miniature',
            self::ThumbnailPrompt        => 'Génération prompt miniature',
            self::GoalSuggestions        => 'Suggestions objectifs',
        };
    }
}
