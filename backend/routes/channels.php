<?php

use App\Models\PredictiveModel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('models.{modelId}.status', function ($user, string $modelId): bool {
    $model = PredictiveModel::find($modelId);

    return $model !== null && $user->can('view', $model);
});
