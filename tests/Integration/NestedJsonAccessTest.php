<?php

use Hibla\HttpClient\Http;

beforeEach(function () {
    Http::startTesting();
});

afterEach(function () {
    Http::stopTesting();
});

describe('Nested JSON Access with Dot Notation', function () {
    beforeEach(function () {
        Http::mock('GET')
            ->url('https://api.example.com/user')
            ->respondJson([
                'id' => 123,
                'name' => 'John Doe',
                'email' => 'john@example.com',
                'profile' => [
                    'age' => 30,
                    'location' => [
                        'city' => 'New York',
                        'country' => 'USA',
                        'coordinates' => [
                            'lat' => 40.7128,
                            'lng' => -74.0060,
                        ],
                    ],
                    'preferences' => [
                        'theme' => 'dark',
                        'notifications' => true,
                    ],
                ],
                'roles' => ['admin', 'user', 'moderator'],
                'metadata' => [
                    'created_at' => '2025-01-01T00:00:00Z',
                    'updated_at' => '2025-10-10T12:00:00Z',
                ],
            ])
            ->register()
        ;
    });

    test('retrieves full JSON when no key specified', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $data = $response->json();

        expect($data)->toBeArray()
            ->and($data)->toHaveKey('id')
            ->and($data)->toHaveKey('name')
            ->and($data)->toHaveKey('profile')
            ->and($data['id'])->toBe(123)
            ->and($data['name'])->toBe('John Doe')
        ;
    });

    test('retrieves top-level key value', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $name = $response->json('name');

        expect($name)->toBe('John Doe');
    });

    test('retrieves one level nested value', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $profile = $response->json('profile');

        expect($profile)->toBeArray()
            ->and($profile)->toHaveKey('age')
            ->and($profile)->toHaveKey('location')
            ->and($profile)->toHaveKey('preferences')
            ->and($profile['age'])->toBe(30)
        ;
    });

    test('retrieves two levels nested value', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $city = $response->json('profile.location.city');

        expect($city)->toBe('New York');
    });

    test('retrieves three levels nested value', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $lat = $response->json('profile.location.coordinates.lat');

        expect($lat)->toBe(40.7128);
    });

    test('retrieves array from nested structure', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $roles = $response->json('roles');

        expect($roles)->toBeArray()
            ->and($roles)->toBe(['admin', 'user', 'moderator'])
            ->and($roles)->toHaveCount(3)
        ;
    });

    test('returns actual value when default provided for existing key', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $theme = $response->json('profile.preferences.theme', 'light');

        expect($theme)->toBe('dark');
    });

    test('returns default value for non-existing key', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $language = $response->json('profile.preferences.language', 'en');

        expect($language)->toBe('en');
    });

    test('returns default value for non-existing nested path', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $missing = $response->json('profile.invalid.path', 'NOT_FOUND');

        expect($missing)->toBe('NOT_FOUND');
    });

    test('retrieves boolean value correctly', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $notifications = $response->json('profile.preferences.notifications');

        expect($notifications)->toBeTrue();
    });

    test('retrieves integer value correctly', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $age = $response->json('profile.age');

        expect($age)->toBe(30)
            ->and($age)->toBeInt()
        ;
    });

    test('retrieves float value correctly', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $lng = $response->json('profile.location.coordinates.lng');

        expect($lng)->toBe(-74.0060)
            ->and($lng)->toBeFloat()
        ;
    });

    test('returns null as default when key does not exist', function () {
        $response = Http::get('https://api.example.com/user')->await();

        $missing = $response->json('does.not.exist');

        expect($missing)->toBeNull();
    });

    test('handles deeply nested paths correctly', function () {
        $response = Http::get('https://api.example.com/user')->await();

        expect($response->json('profile.location.coordinates.lat'))->toBe(40.7128)
            ->and($response->json('profile.location.coordinates.lng'))->toBe(-74.0060)
            ->and($response->json('profile.location.city'))->toBe('New York')
            ->and($response->json('profile.location.country'))->toBe('USA')
        ;
    });

    test('prioritizes literal dot key over nested path', function () {
        Http::mock('GET')
            ->url('https://api.example.com/edge-case')
            ->respondJson([
                'user.name' => 'Literal Dot Key',
                'user' => [
                    'name' => 'Nested Name',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/edge-case')->await();

        $literalDot = $response->json('user.name');

        expect($literalDot)->toBe('Literal Dot Key')
            ->and($literalDot)->not->toBe('Nested Name')
        ;
    });

    test('can access nested value after literal key by getting parent first', function () {
        Http::mock('GET')
            ->url('https://api.example.com/edge-case2')
            ->respondJson([
                'user.name' => 'Literal Dot Key',
                'user' => [
                    'name' => 'Nested Name',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/edge-case2')->await();

        $user = $response->json('user');
        $nestedName = $user['name'];

        expect($nestedName)->toBe('Nested Name');
    });

    test('handles empty string values', function () {
        Http::mock('GET')
            ->url('https://api.example.com/empty')
            ->respondJson([
                'empty' => '',
                'nested' => [
                    'empty' => '',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/empty')->await();

        expect($response->json('empty'))->toBe('')
            ->and($response->json('nested.empty'))->toBe('')
        ;
    });

    test('handles zero values', function () {
        Http::mock('GET')
            ->url('https://api.example.com/zero')
            ->respondJson([
                'zero' => 0,
                'nested' => [
                    'zero' => 0,
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/zero')->await();

        expect($response->json('zero'))->toBe(0)
            ->and($response->json('nested.zero'))->toBe(0)
        ;
    });

    test('handles false boolean values', function () {
        Http::mock('GET')
            ->url('https://api.example.com/false')
            ->respondJson([
                'false' => false,
                'nested' => [
                    'false' => false,
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/false')->await();

        expect($response->json('false'))->toBeFalse()
            ->and($response->json('nested.false'))->toBeFalse()
        ;
    });

    test('handles null values in JSON', function () {
        Http::mock('GET')
            ->url('https://api.example.com/null')
            ->respondJson([
                'null_value' => null,
                'nested' => [
                    'null_value' => null,
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/null')->await();

        expect($response->json('null_value'))->toBeNull()
            ->and($response->json('nested.null_value'))->toBeNull()
        ;
    });

    test('handles empty arrays', function () {
        Http::mock('GET')
            ->url('https://api.example.com/empty-array')
            ->respondJson([
                'empty_array' => [],
                'nested' => [
                    'empty_array' => [],
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/empty-array')->await();

        expect($response->json('empty_array'))->toBe([])
            ->and($response->json('nested.empty_array'))->toBe([])
        ;
    });

    test('handles mixed data types in nested structure', function () {
        Http::mock('GET')
            ->url('https://api.example.com/mixed')
            ->respondJson([
                'data' => [
                    'string' => 'text',
                    'int' => 42,
                    'float' => 3.14,
                    'bool' => true,
                    'null' => null,
                    'array' => [1, 2, 3],
                    'object' => ['key' => 'value'],
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/mixed')->await();

        expect($response->json('data.string'))->toBe('text')
            ->and($response->json('data.int'))->toBe(42)
            ->and($response->json('data.float'))->toBe(3.14)
            ->and($response->json('data.bool'))->toBeTrue()
            ->and($response->json('data.null'))->toBeNull()
            ->and($response->json('data.array'))->toBe([1, 2, 3])
            ->and($response->json('data.object'))->toBe(['key' => 'value'])
        ;
    });

    test('returns default for invalid JSON', function () {
        Http::mock('GET')
            ->url('https://api.example.com/invalid')
            ->respondWith('invalid json{')
            ->register()
        ;

        $response = Http::get('https://api.example.com/invalid')->await();

        expect($response->json('any.key', 'default'))->toBe('default')
            ->and($response->json())->toBeNull()
        ;
    });
});

describe('Nested JSON Access Edge Cases', function () {
    test('handles numeric string keys', function () {
        Http::mock('GET')
            ->url('https://api.example.com/numeric-keys')
            ->respondJson([
                '0' => 'zero',
                '123' => 'number',
                'data' => [
                    '456' => 'nested-number',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/numeric-keys')->await();

        expect($response->json('0'))->toBe('zero')
            ->and($response->json('123'))->toBe('number')
            ->and($response->json('data.456'))->toBe('nested-number')
        ;
    });

    test('handles keys with special characters', function () {
        Http::mock('GET')
            ->url('https://api.example.com/special-chars')
            ->respondJson([
                'key-with-dash' => 'dash',
                'key_with_underscore' => 'underscore',
                'key with space' => 'space',
                'key@symbol' => 'at',
                'data' => [
                    'nested-key' => 'value',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/special-chars')->await();

        expect($response->json('key-with-dash'))->toBe('dash')
            ->and($response->json('key_with_underscore'))->toBe('underscore')
            ->and($response->json('key with space'))->toBe('space')
            ->and($response->json('key@symbol'))->toBe('at')
            ->and($response->json('data.nested-key'))->toBe('value')
        ;
    });

    test('handles very deeply nested structures (5+ levels)', function () {
        Http::mock('GET')
            ->url('https://api.example.com/deep')
            ->respondJson([
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => [
                                    'level6' => 'deep value',
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/deep')->await();

        expect($response->json('level1.level2.level3.level4.level5.level6'))->toBe('deep value');
    });

    test('handles array indices in path', function () {
        Http::mock('GET')
            ->url('https://api.example.com/arrays')
            ->respondJson([
                'users' => [
                    ['name' => 'John', 'age' => 30],
                    ['name' => 'Jane', 'age' => 25],
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/arrays')->await();

        // Get full array
        $users = $response->json('users');
        expect($users)->toBeArray()
            ->and($users)->toHaveCount(2)
        ;

        // Note: Dot notation doesn't support array indices like 'users.0.name'
        // This is expected behavior - user should access array first
        expect($users[0]['name'])->toBe('John')
            ->and($users[1]['name'])->toBe('Jane')
        ;
    });

    test('handles empty key string', function () {
        Http::mock('GET')
            ->url('https://api.example.com/empty-key')
            ->respondJson([
                '' => 'empty key value',
                'data' => 'normal',
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/empty-key')->await();

        expect($response->json(''))->toBe('empty key value')
            ->and($response->json('data'))->toBe('normal')
        ;
    });

    test('handles whitespace in keys', function () {
        Http::mock('GET')
            ->url('https://api.example.com/whitespace')
            ->respondJson([
                ' leading' => 'value1',
                'trailing ' => 'value2',
                ' both ' => 'value3',
                'data' => [
                    ' nested ' => 'value4',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/whitespace')->await();

        expect($response->json(' leading'))->toBe('value1')
            ->and($response->json('trailing '))->toBe('value2')
            ->and($response->json(' both '))->toBe('value3')
            ->and($response->json('data. nested '))->toBe('value4')
        ;
    });

    test('handles unicode characters in keys', function () {
        Http::mock('GET')
            ->url('https://api.example.com/unicode')
            ->respondJson([
                '日本語' => 'Japanese',
                '🎉' => 'emoji',
                'français' => 'French',
                'data' => [
                    '中文' => 'Chinese',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/unicode')->await();

        expect($response->json('日本語'))->toBe('Japanese')
            ->and($response->json('🎉'))->toBe('emoji')
            ->and($response->json('français'))->toBe('French')
            ->and($response->json('data.中文'))->toBe('Chinese')
        ;
    });

    test('handles multiple dots in literal key', function () {
        Http::mock('GET')
            ->url('https://api.example.com/multiple-dots')
            ->respondJson([
                'file.name.txt' => 'literal triple dot',
                'version.1.0.0' => 'version string',
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/multiple-dots')->await();

        expect($response->json('file.name.txt'))->toBe('literal triple dot')
            ->and($response->json('version.1.0.0'))->toBe('version string')
        ;
    });

    test('handles non-associative array root', function () {
        Http::mock('GET')
            ->url('https://api.example.com/array-root')
            ->respondJson([
                ['id' => 1, 'name' => 'First'],
                ['id' => 2, 'name' => 'Second'],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/array-root')->await();

        // When root is array, no key returns full array
        $data = $response->json();
        expect($data)->toBeArray()
            ->and($data)->toHaveCount(2)
            ->and($data[0]['name'])->toBe('First')
        ;

        expect($response->json('0'))->toBe(['id' => 1, 'name' => 'First'])
            ->and($response->json('0.name'))->toBe('First')
            ->and($response->json('1.name'))->toBe('Second')
        ;
    });

    test('handles case sensitivity in keys', function () {
        Http::mock('GET')
            ->url('https://api.example.com/case-sensitive')
            ->respondJson([
                'Name' => 'uppercase N',
                'name' => 'lowercase n',
                'NAME' => 'all caps',
                'data' => [
                    'Value' => 'nested uppercase',
                    'value' => 'nested lowercase',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/case-sensitive')->await();

        // Keys should be case-sensitive
        expect($response->json('Name'))->toBe('uppercase N')
            ->and($response->json('name'))->toBe('lowercase n')
            ->and($response->json('NAME'))->toBe('all caps')
            ->and($response->json('data.Value'))->toBe('nested uppercase')
            ->and($response->json('data.value'))->toBe('nested lowercase')
        ;
    });

    test('handles large JSON structures efficiently', function () {
        $largeArray = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeArray["key{$i}"] = "value{$i}";
        }

        Http::mock('GET')
            ->url('https://api.example.com/large')
            ->respondJson([
                'data' => $largeArray,
                'nested' => [
                    'deep' => $largeArray,
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/large')->await();

        expect($response->json('data.key0'))->toBe('value0')
            ->and($response->json('data.key999'))->toBe('value999')
            ->and($response->json('nested.deep.key500'))->toBe('value500')
        ;
    });

    test('handles mixed array and object nesting', function () {
        Http::mock('GET')
            ->url('https://api.example.com/mixed-nesting')
            ->respondJson([
                'users' => [
                    [
                        'name' => 'John',
                        'contacts' => [
                            'email' => 'john@example.com',
                            'phones' => ['123', '456'],
                        ],
                    ],
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/mixed-nesting')->await();

        $users = $response->json('users');
        expect($users[0]['contacts']['email'])->toBe('john@example.com')
            ->and($users[0]['contacts']['phones'])->toBe(['123', '456'])
        ;
    });

    test('returns null when accessing non-array value as nested', function () {
        Http::mock('GET')
            ->url('https://api.example.com/non-array')
            ->respondJson([
                'value' => 'string',
                'number' => 42,
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/non-array')->await();

        // Trying to access string value as nested should return default
        expect($response->json('value.nested'))->toBeNull()
            ->and($response->json('value.nested', 'default'))->toBe('default')
            ->and($response->json('number.nested'))->toBeNull()
        ;
    });

    test('handles backslash in key names', function () {
        Http::mock('GET')
            ->url('https://api.example.com/backslash')
            ->respondJson([
                'path\\to\\file' => 'windows path',
                'data' => [
                    'nested\\key' => 'value',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/backslash')->await();

        expect($response->json('path\\to\\file'))->toBe('windows path')
            ->and($response->json('data.nested\\key'))->toBe('value')
        ;
    });

    test('handles scientific notation numbers', function () {
        Http::mock('GET')
            ->url('https://api.example.com/scientific')
            ->respondJson([
                'small' => 1.23e-10,
                'large' => 9.87e20,
                'data' => [
                    'nested' => 5.5e5,
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/scientific')->await();

        expect($response->json('small'))->toBe(1.23e-10)
            ->and($response->json('large'))->toBe(9.87e20)
            ->and($response->json('data.nested'))->toBeNumeric()
            ->and($response->json('data.nested'))->toBe(550000)
        ;
    });

    test('handles trailing dots in key parameter', function () {
        Http::mock('GET')
            ->url('https://api.example.com/trailing-dots')
            ->respondJson([
                'data' => [
                    'value' => 'test',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/trailing-dots')->await();

        // Trailing dots should be handled gracefully (likely return null)
        expect($response->json('data.'))->toBeNull()
            ->and($response->json('data.value.'))->toBeNull()
        ;
    });

    test('handles leading dots in key parameter', function () {
        Http::mock('GET')
            ->url('https://api.example.com/leading-dots')
            ->respondJson([
                'data' => [
                    'value' => 'test',
                ],
            ])
            ->register()
        ;

        $response = Http::get('https://api.example.com/leading-dots')->await();

        // Leading dots should be handled gracefully (likely return null)
        expect($response->json('.data'))->toBeNull()
            ->and($response->json('.data.value'))->toBeNull()
        ;
    });

    test('handles consecutive dots in key parameter', function () {
        Http::mock('GET')
            ->url('https://api.example.com/consecutive-dots')
            ->respondJson([
                'data' => [
                    'value' => 'test',
                ],
            ])
            ->register();

        $response = Http::get('https://api.example.com/consecutive-dots')->await();

        // Consecutive dots should be handled gracefully
        expect($response->json('data..value'))->toBeNull()
            ->and($response->json('data...value'))->toBeNull();
    });
});
