<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CloudinaryUploader
{
    public static function enabled(): bool
    {
        return filled(config('services.cloudinary.cloud_name'))
            && filled(config('services.cloudinary.api_key'))
            && filled(config('services.cloudinary.api_secret'));
    }

    public static function uploadFile(UploadedFile $file, string $folder): string
    {
        if (!self::enabled()) {
            return $file->store($folder, 'public');
        }

        [$timestamp, $signature] = self::sign(['folder' => $folder]);

        $response = Http::attach('file', file_get_contents($file->getRealPath()), $file->getClientOriginalName())
            ->post(self::endpoint('upload'), [
                'folder' => $folder,
                'api_key' => config('services.cloudinary.api_key'),
                'timestamp' => $timestamp,
                'signature' => $signature,
            ]);

        return self::secureUrl($response);
    }

    public static function uploadContent(string $binary, string $folder, string $mime = 'image/png', string $extension = 'png'): string
    {
        if (!self::enabled()) {
            $path = $folder.'/'.uniqid('', true).'.'.$extension;
            Storage::disk('public')->put($path, $binary);
            return $path;
        }

        [$timestamp, $signature] = self::sign(['folder' => $folder]);

        $response = Http::asForm()->post(self::endpoint('upload'), [
            'file' => "data:{$mime};base64,".base64_encode($binary),
            'folder' => $folder,
            'api_key' => config('services.cloudinary.api_key'),
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);

        return self::secureUrl($response);
    }

    private static function secureUrl(Response $response): string
    {
        $url = $response->json('secure_url');

        if (!$url) {
            Log::error('Cloudinary upload failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Cloudinary upload failed: '.($response->json('error.message') ?? $response->body()));
        }

        return $url;
    }

    public static function delete(?string $value): void
    {
        if (!$value) {
            return;
        }

        if (!self::enabled() || !str_contains($value, 'res.cloudinary.com')) {
            Storage::disk('public')->delete($value);
            return;
        }

        $publicId = self::publicIdFromUrl($value);
        if (!$publicId) {
            return;
        }

        [$timestamp, $signature] = self::sign(['public_id' => $publicId]);

        Http::asForm()->post(self::endpoint('destroy'), [
            'public_id' => $publicId,
            'api_key' => config('services.cloudinary.api_key'),
            'timestamp' => $timestamp,
            'signature' => $signature,
        ]);
    }

    private static function sign(array $params): array
    {
        $timestamp = time();
        $params['timestamp'] = $timestamp;
        ksort($params);

        $toSign = collect($params)
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode('&');

        $signature = sha1($toSign.config('services.cloudinary.api_secret'));

        return [$timestamp, $signature];
    }

    private static function endpoint(string $action): string
    {
        $cloud = config('services.cloudinary.cloud_name');
        return "https://api.cloudinary.com/v1_1/{$cloud}/auto/{$action}";
    }

    private static function publicIdFromUrl(string $url): ?string
    {
        if (preg_match('#/upload/(?:v\d+/)?(.+)\.[a-zA-Z0-9]+(?:\?.*)?$#', $url, $m)) {
            return $m[1];
        }
        return null;
    }
}
