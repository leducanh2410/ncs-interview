<?php

namespace App\Console\Commands;

use Aws\Rekognition\Exception\RekognitionException;
use Aws\Rekognition\RekognitionClient;
use Illuminate\Console\Command;

class ManageRekognitionCollection extends Command
{
    protected $signature = 'rekognition:collection
                            {action : The action to perform (create|delete|list|describe|faces)}
                            {--collection= : The collection ID (required for create, delete, describe, faces)}
                            {--force : Force deletion without confirmation}
                            {--max=100 : Max results per page (default: 100)}';

    protected $description = 'Manage AWS Rekognition collections (create, delete, list, describe, faces)';

    private RekognitionClient $client;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        $this->initializeClient();

        return match ($action) {
            'create' => $this->createCollection(),
            'delete' => $this->deleteCollection(),
            'list' => $this->listCollections(),
            'describe' => $this->describeCollection(),
            'faces' => $this->listFaces(),
            default => $this->invalidAction(),
        };
    }

    private function initializeClient(): void
    {
        $config = [
            'version' => 'latest',
            'region' => config('services.aws.region'),
        ];

        $key = config('services.aws.key');
        $secret = config('services.aws.secret');

        if ($key && $secret) {
            $config['credentials'] = [
                'key' => $key,
                'secret' => $secret,
            ];
        }

        $this->client = new RekognitionClient($config);
    }

    private function createCollection(): int
    {
        $collectionId = $this->option('collection') ?? config('services.aws.rekognition.collection');

        if (empty($collectionId)) {
            $this->error('Collection ID is required. Use --collection=<id> or set AWS_REKOGNITION_COLLECTION in .env');

            return self::FAILURE;
        }

        $this->info("Creating collection: {$collectionId}");

        try {
            $result = $this->client->createCollection([
                'CollectionId' => $collectionId,
            ]);

            $this->info('Collection created successfully!');
            $this->info('Collection ARN: ' . ($result->get('CollectionArn') ?? 'N/A'));

            return self::SUCCESS;
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'ResourceAlreadyExistsException') {
                $this->warn("Collection '{$collectionId}' already exists.");

                return self::SUCCESS;
            }

            $this->error('AWS Rekognition error:');
            $this->error("  Code: {$e->getAwsErrorCode()}");
            $this->error("  Message: {$e->getAwsErrorMessage()}");

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function deleteCollection(): int
    {
        $collectionId = $this->option('collection') ?? config('services.aws.rekognition.collection');

        if (empty($collectionId)) {
            $this->error('Collection ID is required. Use --collection=<id> or set AWS_REKOGNITION_COLLECTION in .env');

            return self::FAILURE;
        }

        $this->info("Deleting collection: {$collectionId}");

        try {
            // First, check if collection exists and get face count
            $describeResult = $this->client->describeCollection([
                'CollectionId' => $collectionId,
            ]);

            $faceCount = $describeResult->get('FaceCount') ?? 0;

            if ($faceCount > 0) {
                $this->warn("This collection contains {$faceCount} face(s).");
                $this->warn('Deleting the collection will permanently remove all faces.');
            }

            if (!$this->option('force')) {
                if (!$this->confirm("Do you really wish to delete collection '{$collectionId}'?")) {
                    $this->info('Operation cancelled.');

                    return self::SUCCESS;
                }
            }

            $this->client->deleteCollection([
                'CollectionId' => $collectionId,
            ]);

            $this->info('Collection deleted successfully!');

            return self::SUCCESS;
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $this->warn("Collection '{$collectionId}' does not exist.");

                return self::SUCCESS;
            }

            $this->error('AWS Rekognition error:');
            $this->error("  Code: {$e->getAwsErrorCode()}");
            $this->error("  Message: {$e->getAwsErrorMessage()}");

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function listCollections(): int
    {
        $this->info('Listing Rekognition collections...');

        try {
            $collections = [];
            $nextToken = null;

            do {
                $params = ['MaxResults' => 100];
                if ($nextToken) {
                    $params['NextToken'] = $nextToken;
                }

                $result = $this->client->listCollections($params);

                foreach ($result->get('CollectionIds') as $collectionId) {
                    $collections[] = $collectionId;
                }

                $nextToken = $result->get('NextToken');
            } while ($nextToken);

            if (empty($collections)) {
                $this->warn('No collections found.');

                return self::SUCCESS;
            }

            $this->info('Found ' . count($collections) . ' collection(s):');

            $rows = [];
            foreach ($collections as $collectionId) {
                try {
                    $describeResult = $this->client->describeCollection([
                        'CollectionId' => $collectionId,
                    ]);

                    $rows[] = [
                        $collectionId,
                        $describeResult->get('FaceCount') ?? 0,
                        $describeResult->get('CreationTimestamp')?->format('Y-m-d H:i:s') ?? 'N/A',
                    ];
                } catch (\Exception) {
                    $rows[] = [$collectionId, 'N/A', 'N/A'];
                }
            }

            $this->table(['Collection ID', 'Face Count', 'Created At'], $rows);

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function describeCollection(): int
    {
        $collectionId = $this->option('collection') ?? config('services.aws.rekognition.collection');

        if (empty($collectionId)) {
            $this->error('Collection ID is required. Use --collection=<id> or set AWS_REKOGNITION_COLLECTION in .env');

            return self::FAILURE;
        }

        $this->info("Describing collection: {$collectionId}");

        try {
            $result = $this->client->describeCollection([
                'CollectionId' => $collectionId,
            ]);

            $this->info('Collection Details:');
            $this->info("  Collection ID: {$collectionId}");
            $this->info('  Collection ARN: ' . ($result->get('CollectionArn') ?? 'N/A'));
            $this->info('  Face Count: ' . ($result->get('FaceCount') ?? 0));
            $this->info('  Created At: ' . ($result->get('CreationTimestamp')?->format('Y-m-d H:i:s') ?? 'N/A'));
            $this->info('  Updated At: ' . ($result->get('UpdateTimestamp')?->format('Y-m-d H:i:s') ?? 'N/A'));

            return self::SUCCESS;
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $this->error("Collection '{$collectionId}' does not exist.");

                return self::FAILURE;
            }

            $this->error('AWS Rekognition error:');
            $this->error("  Code: {$e->getAwsErrorCode()}");
            $this->error("  Message: {$e->getAwsErrorMessage()}");

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function listFaces(): int
    {
        $collectionId = $this->option('collection') ?? config('services.aws.rekognition.collection');
        $maxResults = (int) $this->option('max');

        if (empty($collectionId)) {
            $this->error('Collection ID is required. Use --collection=<id> or set AWS_REKOGNITION_COLLECTION in .env');

            return self::FAILURE;
        }

        $this->info("Listing faces in collection: {$collectionId}");

        try {
            $allFaces = [];
            $nextToken = null;
            $pageCount = 0;

            do {
                $params = [
                    'CollectionId' => $collectionId,
                    'MaxResults' => min(max($maxResults, 1), 4096),
                ];

                if ($nextToken) {
                    $params['NextToken'] = $nextToken;
                }

                $result = $this->client->listFaces($params);

                $faces = $result->get('Faces') ?? [];
                $pageCount++;

                $this->info("Page {$pageCount}: " . count($faces) . ' face(s)');

                foreach ($faces as $face) {
                    $allFaces[] = [
                        'Face ID' => $face['FaceId'] ?? 'N/A',
                        'External ID' => $face['ExternalImageId'] ?? 'N/A',
                        'Image ID' => $face['ImageId'] ?? 'N/A',
                    ];
                }

                $nextToken = $result->get('NextToken');
            } while ($nextToken);

            if (empty($allFaces)) {
                $this->warn('No faces found in this collection.');

                return self::SUCCESS;
            }

            $this->newLine();
            $this->info('Total faces: ' . count($allFaces));
            $this->table(['Face ID', 'External ID', 'Image ID'], $allFaces);

            return self::SUCCESS;
        } catch (RekognitionException $e) {
            if ($e->getAwsErrorCode() === 'ResourceNotFoundException') {
                $this->error("Collection '{$collectionId}' does not exist.");

                return self::FAILURE;
            }

            $this->error('AWS Rekognition error:');
            $this->error("  Code: {$e->getAwsErrorCode()}");
            $this->error("  Message: {$e->getAwsErrorMessage()}");

            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function invalidAction(): int
    {
        $this->error('Invalid action. Valid actions are: create, delete, list, describe, faces');

        return self::FAILURE;
    }
}
