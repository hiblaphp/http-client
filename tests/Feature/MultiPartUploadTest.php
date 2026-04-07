<?php

use Hibla\HttpClient\Http;

use function Hibla\await;

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
