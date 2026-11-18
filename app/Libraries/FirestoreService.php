<?php

namespace App\Libraries;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Firestore connection for this app. All three HRIS apps share the
 * primazentra-hris Firebase project, but each app writes only to the
 * collections it owns and reads other apps' data through their REST API.
 */
class FirestoreService
{
    private static ?self $instance = null;
    private FirestoreClient $db;

    private function __construct()
    {
        $path = WRITEPATH . 'credentials/firebase_key.json';
        if (! is_file($path)) {
            throw new \RuntimeException('Firebase key not found at: ' . $path);
        }

        $key = json_decode((string) file_get_contents($path), true);
        if (! is_array($key) || empty($key['project_id'])) {
            throw new \RuntimeException('Invalid firebase_key.json: missing project_id');
        }

        // Credentials are passed as a pre-built object; the client otherwise
        // falls back to Application Default Credentials (same as the main app).
        $this->db = new FirestoreClient([
            'projectId'   => $key['project_id'],
            'credentials' => new ServiceAccountCredentials(FirestoreClient::FULL_CONTROL_SCOPE, $key),
        ]);
    }

    public static function db(): FirestoreClient
    {
        return (self::$instance ??= new self())->db;
    }
}
