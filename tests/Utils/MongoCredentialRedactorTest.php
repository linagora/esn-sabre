<?php

namespace ESN\Utils;

use PHPUnit\Framework\Attributes\DataProvider;

class MongoCredentialRedactorTest extends \PHPUnit\Framework\TestCase {

    public static function messages(): array {
        return [
            'unescaped @: libmongoc quotes the end of the password as a host' => [
                "Failed to parse MongoDB URI: 'mongodb://u:p@ss:w0rd@mongo:27017/sabre'. "
                    . "Invalid host specifier \"ss:w0rd@mongo:27017\": Invalid host specifier 'ss:w0rd@mongo:27017': "
                    . "Invalid port string 'w0rd@mongo:27017': Port string is not a valid integer.",
                'mongodb://u:p@ss:w0rd@mongo:27017/sabre',
                "Failed to parse MongoDB URI: 'mongodb://***@mongo:27017/sabre'. "
                    . "Invalid host specifier \"***@mongo:27017\": Invalid host specifier '***@mongo:27017': "
                    . "Invalid port string '***@mongo:27017': Port string is not a valid integer.",
            ],
            'unescaped /: only the URI is quoted' => [
                "Failed to parse MongoDB URI: 'mongodb://u:se/cr@et@mongo:27017/sabre'. Password must not have unescaped chars.",
                'mongodb://u:se/cr@et@mongo:27017/sabre',
                "Failed to parse MongoDB URI: 'mongodb://***@mongo:27017/sabre'. Password must not have unescaped chars.",
            ],
            'srv URI' => [
                'cannot reach mongodb+srv://admin:s3cret@cluster.example.com/sabre',
                null,
                'cannot reach mongodb+srv://***@cluster.example.com/sabre',
            ],
            'password quoted outside a URI' => [
                'Authentication failed for password p@ss w"rd',
                'mongodb://u:p@ss w"rd@mongo/sabre',
                'Authentication failed for password ***',
            ],
            'percent-encoded password quoted decoded' => [
                'bad password p@ss',
                'mongodb://u:p%40ss@mongo/sabre',
                'bad password ***',
            ],
            'diagnostic without credentials is kept' => [
                'Index with name: uri_1 already exists with different options, contact admin@example.com',
                'mongodb://sabre:Zq8-xT4v@mongo/sabre',
                'Index with name: uri_1 already exists with different options, contact admin@example.com',
            ],
            'short password is only removed from the URI' => [
                "No suitable servers found: 'mongodb://u:1@mongo:27017/sabre': timeout after 10000ms",
                'mongodb://u:1@mongo:27017/sabre',
                "No suitable servers found: 'mongodb://***@mongo:27017/sabre': timeout after 10000ms",
            ],
            'URI without credentials is kept' => [
                "No suitable servers found: 'mongodb://mongo:27017/sabre'",
                'mongodb://mongo:27017/sabre',
                "No suitable servers found: 'mongodb://mongo:27017/sabre'",
            ],
        ];
    }

    #[DataProvider('messages')]
    public function testRedact(string $message, ?string $connectionString, string $expected): void {
        $this->assertSame($expected, MongoCredentialRedactor::redact($message, $connectionString));
    }
}
