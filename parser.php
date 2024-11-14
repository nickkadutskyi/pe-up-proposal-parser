<?php

class JSONParser {
    private $db;
    private $requiredFields = [
        'id',
        'status.status',
        'marketplaceJobPosting.id',
        'marketplaceJobPosting.content.title',
        'marketplaceJobPosting.content.description',
        'marketplaceJobPosting.ownership.team.name',
        'proposalCoverLetter'
    ];

    public function __construct() {
        try {
            $this->db = new SQLite3('proposals.db');
            $this->createTable();
        } catch (Exception $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }

    private function createTable() {
        $sql = "CREATE TABLE IF NOT EXISTS proposals (
            id TEXT PRIMARY KEY,
            status TEXT,
            job_posting_id TEXT,
            job_title TEXT,
            job_description TEXT,
            team_name TEXT,
            cover_letter TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $this->db->exec($sql);
    }

    private function getNestedValue($array, $path) {
        $keys = explode('.', $path);
        $value = $array;

        foreach ($keys as $key) {
            if (!isset($value[$key])) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    public function validateAndParseJSON($jsonData) {
        if (!isset($jsonData['data']['vendorProposals']['edges'])) {
            throw new Exception("Invalid JSON structure");
        }

        $proposals = [];
        foreach ($jsonData['data']['vendorProposals']['edges'] as $edge) {
            $node = $edge['node'];

            // Validate required fields
            foreach ($this->requiredFields as $field) {
                if ($this->getNestedValue($node, $field) === null) {
                    throw new Exception("Missing required field: $field");
                }
            }

            $proposals[] = [
                'id' => $node['id'],
                'status' => $node['status']['status'],
                'job_posting_id' => $node['marketplaceJobPosting']['id'],
                'job_title' => $node['marketplaceJobPosting']['content']['title'],
                'job_description' => $node['marketplaceJobPosting']['content']['description'],
                'team_name' => $node['marketplaceJobPosting']['ownership']['team']['name'],
                'cover_letter' => $node['proposalCoverLetter']
            ];
        }

        return $proposals;
    }

    public function saveProposals($proposals) {
        $this->db->exec('BEGIN TRANSACTION');

        try {
            $stmt = $this->db->prepare('INSERT OR REPLACE INTO proposals 
                (id, status, job_posting_id, job_title, job_description, team_name, cover_letter) 
                VALUES (:id, :status, :job_posting_id, :job_title, :job_description, :team_name, :cover_letter)');

            foreach ($proposals as $proposal) {
                $stmt->bindValue(':id', $proposal['id'], SQLITE3_TEXT);
                $stmt->bindValue(':status', $proposal['status'], SQLITE3_TEXT);
                $stmt->bindValue(':job_posting_id', $proposal['job_posting_id'], SQLITE3_TEXT);
                $stmt->bindValue(':job_title', $proposal['job_title'], SQLITE3_TEXT);
                $stmt->bindValue(':job_description', $proposal['job_description'], SQLITE3_TEXT);
                $stmt->bindValue(':team_name', $proposal['team_name'], SQLITE3_TEXT);
                $stmt->bindValue(':cover_letter', $proposal['cover_letter'], SQLITE3_TEXT);

                $stmt->execute();
            }

            $this->db->exec('COMMIT');
            return true;
        } catch (Exception $e) {
            $this->db->exec('ROLLBACK');
            throw $e;
        }
    }
}

// Handle the upload
header('Content-Type: application/json');

try {
    if (!isset($_FILES['jsonFile'])) {
        throw new Exception("No file uploaded");
    }

    $file = $_FILES['jsonFile'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("File upload failed with error code: " . $file['error']);
    }

    if ($file['type'] !== 'application/json') {
        throw new Exception("Invalid file type. Only JSON files are allowed.");
    }

    $jsonContent = file_get_contents($file['tmp_name']);
    $jsonData = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON format: " . json_last_error_msg());
    }

    $parser = new JSONParser();
    $proposals = $parser->validateAndParseJSON($jsonData);
    $parser->saveProposals($proposals);

    echo json_encode([
        'success' => true,
        'message' => 'File processed successfully. ' . count($proposals) . ' proposals saved.'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
