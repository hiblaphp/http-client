<?php

declare(strict_types=1);

use Hibla\HttpClient\Http;

use function Hibla\await;

describe('Mocked Multipart Uploads', function () {
    beforeEach(function () {
        Http::startTesting();
    });

    afterEach(function () {
        Http::stopTesting();
    });

    it('can mock and assert multipart requests', function () {
        Http::mock('POST')
            ->url('https://api.example.com/upload')
            ->respondJson(['success' => true, 'message' => 'File received'])
            ->register()
        ;

        $tempFile = sys_get_temp_dir() . '/mock_avatar.png';
        file_put_contents($tempFile, 'fake binary png data');

        $response = await(
            Http::request()
                ->withMultipart([
                    'user_id' => '404',
                    'type' => 'profile_picture',
                ])
                ->withFile('avatar', $tempFile, 'custom_name.png', 'image/png')
                ->post('https://api.example.com/upload')
        );

        expect($response->status())->toBe(200)
            ->and($response->json('success'))->toBeTrue()
            ->and($response->json('message'))->toBe('File received')
        ;

        $lastRequest = Http::getLastRequest();
        expect($lastRequest)->not->toBeNull();

        $payload = $lastRequest->getJson();

        expect($payload)->toBeArray()
            ->and($payload['user_id'])->toBe('404')
            ->and($payload['type'])->toBe('profile_picture')
            ->and($payload['avatar'])->toContain('[File: custom_name.png | MIME: image/png]')
        ;

        @unlink($tempFile);
    });
});

describe('Real Network Multipart Uploads', function () {
    it('successfully uploads files and fields to httpbin.org', function () {
        $tempFile = sys_get_temp_dir() . '/real_upload_test.txt';
        $fileContent = 'This is real data being sent over the internet!';
        file_put_contents($tempFile, $fileContent);

        $response = await(
            Http::request()
                ->withMultipart([
                    'project' => 'Hibla',
                    'version' => '1.0.0',
                ])
                ->withFile('document', $tempFile)
                ->post('https://httpbin.org/post')
        );

        expect($response->successful())->toBeTrue();

        $data = $response->json();

        expect($data['form'])->toBeArray()
            ->and($data['form']['project'])->toBe('Hibla')
            ->and($data['form']['version'])->toBe('1.0.0')
        ;

        expect($data['files'])->toBeArray()
            ->and($data['files']['document'])->toBe($fileContent)
        ;

        @unlink($tempFile);
    });
})->skipOnCI();
