<?php

require 'vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();
$dotenv->required(['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_REGION', 'AWS_BUCKET_NAME'])->notEmpty();

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

header('Content-Type: application/json');

function getJsonFromS3($s3Path) {
    try {
        $s3Client = new S3Client([
            'version' => 'latest',
            'region'  => $_ENV['AWS_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ]
        ]);

        $result = $s3Client->getObject([
            'Bucket' => $_ENV['AWS_BUCKET_NAME'],
            'Key'    => $s3Path
        ]);

        $content = $result['Body']->getContents();
        
        // Replace NaN with null as NaN is not valid JSON
        $content = preg_replace('/:\s*NaN\s*,/', ': null,', $content);
        
        $jsonData = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON Error: ' . json_last_error_msg() . 
                              "\nFirst 100 chars of content: " . substr($content, 0, 100));
        }

        return [
            'status' => 'success',
            'data' => $jsonData
        ];

    } catch (AwsException $e) {
        return [
            'status' => 'error',
            'message' => 'AWS Error: ' . $e->getMessage()
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
    }
}

function isValidJson($string) {
    json_decode($string);
    return json_last_error() === JSON_ERROR_NONE;
}

$postData = json_decode(file_get_contents('php://input'), true);

if (!isset($postData['s3_path'])) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing s3_path parameter'
    ]);
    exit;
}

function invokeAWSLambda($jsonData) {
    try {
        $lambda = new Aws\Lambda\LambdaClient([
            'version' => 'latest',
            'region'  => $_ENV['AWS_REGION'],
            'credentials' => [
                'key'    => $_ENV['AWS_ACCESS_KEY_ID'],
                'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'],
            ],
            'http' => [
                'timeout' => 30
            ]
        ]);

        if (empty($jsonData)) {
            throw new InvalidArgumentException('Empty payload');
        }

        $result = $lambda->invoke([
            'FunctionName' => $_ENV['AWS_LAMBDA_FUNCTION_NAME'],
            'InvocationType' => 'RequestResponse',
            'LogType' => 'Tail',
            'Payload' => json_encode([
                'body' => json_encode($jsonData)  // Double encode for Lambda
            ]),
            'ClientContext' => base64_encode(json_encode([
                'source' => 'syncProcessor',
                'timestamp' => time()
            ]))
        ]);

        $payload = json_decode((string) $result->get('Payload'), true);
        if (isset($payload['FunctionError'])) {
            throw new Exception($payload['errorMessage'] ?? 'Lambda execution failed');
        }

        // Parse the response body
        $responseBody = isset($payload['body']) ? json_decode($payload['body'], true) : null;

        // Get request ID from logs
        $logResult = base64_decode($result->get('LogResult') ?? '');
        preg_match('/RequestId: ([^\s]+)/', $logResult, $matches);
        $requestId = $matches[1] ?? 'unknown';

        if ($payload['statusCode'] !== 200) {
            return [
                'success' => false,
                'message' => $responseBody['error'] ?? 'Unknown error',
                'data' => null,
                'request_id' => $requestId,
                'status_code' => $payload['statusCode']
            ];
        }

        return [
            'success' => true,
            'message' => $responseBody['message'] ?? 'Success',
            'data' => $responseBody['data'] ?? null,
            'request_id' => $requestId,
            'status_code' => 200
        ];

    } catch (Aws\Lambda\Exception\LambdaException $e) {
        return [
            'success' => false,
            'message' => 'Lambda Error: ' . $e->getMessage(),
            'data' => null,
            'request_id' => null,
            'status_code' => 500
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => $e->getMessage(),
            'data' => null,
            'request_id' => null,
            'status_code' => 500
        ];
    }
}

$s3Path = $postData['s3_path'];
$result = getJsonFromS3($s3Path);

if ($result['status'] === 'success') {
    $lambdaResult = invokeAWSLambda($result['data']);
    echo json_encode($lambdaResult);
} else {
    echo json_encode($result);
}