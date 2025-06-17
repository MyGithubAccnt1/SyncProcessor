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
        $jsonData = json_decode($content, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON content');
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
            ]
        ]);

        $result = $lambda->invoke([
            'FunctionName' => $_ENV['AWS_LAMBDA_FUNCTION_NAME'],
            'InvocationType' => 'RequestResponse',
            'Payload' => json_encode($jsonData)
        ]);

        return [
            'status' => 'success',
            'lambda_response' => json_decode((string) $result->get('Payload'), true)
        ];

    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Lambda Error: ' . $e->getMessage()
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