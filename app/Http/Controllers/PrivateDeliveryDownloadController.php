<?php

namespace App\Http\Controllers;

use App\Models\PlanningDelivery;
use App\Models\StoredFile;
use App\Services\Documents\PrivateDeliveryDownloadService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PrivateDeliveryDownloadController
{
    public function __invoke(Request $request, PlanningDelivery $delivery, StoredFile $file, PrivateDeliveryDownloadService $downloads): StreamedResponse
    {
        return $downloads->response($request->user(), $delivery, $file);
    }
}
