<?php

/**
 * Cleanup orphaned files
 */
function cleanupOrphanedFiles(PDO $pdo): array
{
    $baseDir = __DIR__ . '/../storage/applicants/';
    $deletedCount = 0;
    $errors = [];

    // Validate directory exists
    if (!is_dir($baseDir)) {
        return [
            'deleted_folders' => 0,
            'errors' => ['Base directory not found']
        ];
    }

    $folders = glob($baseDir . '*', GLOB_ONLYDIR);

    foreach ($folders as $folder) {
        try {
            $folderName = basename($folder);
            $parts = explode('_', $folderName);
            $phone = end($parts);

            $stmt = $pdo->prepare("SELECT id FROM medical_applicants WHERE ic_phone = ?");
            $stmt->execute([$phone]);

            if (!$stmt->fetch()) {
                // Delete files first
                $files = glob("$folder/*.*");
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }

                // Then remove directory
                if (rmdir($folder)) {
                    $deletedCount++;
                }
            }
        } catch (Exception $e) {
            $errors[] = "Failed to process {$folderName}: " . $e->getMessage();
        }
    }

    return [
        'deleted_folders' => $deletedCount,
        'errors' => $errors
    ];
}
