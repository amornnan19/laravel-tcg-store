<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `/admin/slips/{payment}`: a customer's slip, streamed from the private disk to staff only. The route carries `auth` and
 * `can:view,payment` (staff); there is never a public URL for a slip. JPEG and PNG show inline, anything else (HEIC) is
 * a download.
 */
class SlipController extends Controller
{
    private const INLINE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

    public function __invoke(Payment $payment): StreamedResponse
    {
        $path = $payment->slip_path;
        $disk = Storage::disk('local');

        abort_if(blank($path) || ! $disk->exists($path), 404);

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inlineType = self::INLINE_TYPES[$extension] ?? null;

        return $disk->response($path, 'slip-'.$payment->getKey().'.'.$extension, [
            'Content-Type' => $inlineType ?? 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], $inlineType !== null ? 'inline' : 'attachment');
    }
}
