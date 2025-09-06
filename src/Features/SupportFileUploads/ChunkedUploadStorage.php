<?php

namespace Livewire\Features\SupportFileUploads;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ChunkedUploadStorage
{
    public function storeChunk($uploadId, $chunkIndex, $chunkData, $chunkSize, $fileExtension = '')
    {
        try {
            $storage = FileUploadConfiguration::storage();
            $filePath = $this->getUploadFilePath($uploadId, $fileExtension);

            // Calculate the position where this chunk should be written
            $offset = $chunkIndex * $chunkSize;

            // Write chunk data at the specific position in the file
            return $this->writeAtPosition($storage, $filePath, $chunkData, $offset);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function writeAtPosition($storage, $filePath, $data, $offset)
    {
        try {
            // For local disk storage, use direct file operations for better performance
            if (method_exists($storage, 'path')) {
                $actualPath = $storage->path($filePath);

                // Ensure the directory exists using Laravel's Storage facade for proper permissions
                $directory = dirname($actualPath);
                if (!is_dir($directory)) {
                    // Let Laravel's Storage facade handle directory creation with appropriate permissions
                    $relativeDirPath = str_replace($storage->path(''), '', $directory);
                    if (!empty($relativeDirPath)) {
                        // Create directory structure using Storage facade
                        $pathParts = explode(DIRECTORY_SEPARATOR, trim($relativeDirPath, DIRECTORY_SEPARATOR));
                        $buildPath = '';
                        foreach ($pathParts as $part) {
                            $buildPath .= $part . DIRECTORY_SEPARATOR;
                            if (!$storage->exists($buildPath . '.gitkeep')) {
                                $storage->put($buildPath . '.gitkeep', '');
                                $storage->delete($buildPath . '.gitkeep');
                            }
                        }
                    } else {
                        // Fallback to mkdir with cross-platform default permissions
                        mkdir($directory, 0755, true);
                    }
                }

                // Open file for writing at specific position with explicit binary mode
                $handle = fopen($actualPath, 'c+b'); // c+b: create/open for read-write, binary mode
                if (!$handle) {
                    return false;
                }

                try {
                    // Set binary mode explicitly for cross-platform compatibility
                    if (function_exists('stream_set_write_buffer')) {
                        stream_set_write_buffer($handle, 0); // Disable buffering for immediate writes
                    }

                    // Seek to the correct position
                    if (fseek($handle, $offset, SEEK_SET) !== 0) {
                        fclose($handle);
                        return false;
                    }

                    // Write the chunk data at this position
                    $bytesWritten = fwrite($handle, $data);

                    // Flush data to disk for reliability across platforms
                    if (function_exists('fflush')) {
                        fflush($handle);
                    }

                    // Force sync to disk on systems that support it
                    if (function_exists('fsync') && is_resource($handle)) {
                        fsync($handle);
                    }

                    fclose($handle);

                    return $bytesWritten === strlen($data);
                } catch (\Exception $e) {
                    fclose($handle);
                    return false;
                }
            }

            // Remote storage is not supported for chunked uploads
            throw new \RuntimeException('Chunked uploads are only supported with local storage. Remote storage (S3, FTP, etc.) is not supported.');

        } catch (\Exception $e) {
            return false;
        }
    }

    public function assembleFile($uploadId, $fileInfo)
    {
        try {
            $storage = FileUploadConfiguration::storage();

            // Extract file extension from original filename
            $originalName = $fileInfo['name'] ?? '';
            $extension = pathinfo($originalName, PATHINFO_EXTENSION);
            $tempFilePath = $this->getUploadFilePath($uploadId, $extension);

            // Verify the assembled file exists
            if (!$storage->exists($tempFilePath)) {
                return null;
            }

            // Validate the assembled file against Livewire's rules
            if (!$this->validateAssembledFile($storage, $tempFilePath, $fileInfo)) {
                // Clean up invalid file
                $storage->delete($tempFilePath);
                return null;
            }

            // Generate final filename using the same method as regular uploads
            $hash = str()->random(40);
            $filename = $hash . '.' . $extension;
            $finalPath = FileUploadConfiguration::path($filename);

            // Move the assembled file to its final location
            $success = $storage->move($tempFilePath, $finalPath);

            if ($success) {
                // Create metadata file
                $this->createMetadataFile($filename, $fileInfo);

                return $finalPath;
            }

            return null;

        } catch (\Exception $e) {
            // Clean up the temporary file on error
            $this->cleanup($uploadId, $fileInfo);
            return null;
        }
    }

    public function cleanup($uploadId, $fileInfo = null)
    {
        try {
            $storage = FileUploadConfiguration::storage();

            if ($fileInfo) {
                // Extract file extension from original filename
                $originalName = $fileInfo['name'] ?? '';
                $extension = pathinfo($originalName, PATHINFO_EXTENSION);
                $filePath = $this->getUploadFilePath($uploadId, $extension);
            } else {
                // If no file info, try to find and delete any file with this uploadId
                $filePath = $this->getUploadFilePath($uploadId);
            }

            // Delete the temporary upload file
            $storage->delete($filePath);
        } catch (\Exception $e) {
            // Silently fail cleanup - it will be handled by regular cleanup
        }
    }

    public function cleanupExpiredSessions()
    {
        // This would be called by a scheduled task or during regular cleanup
        // For now, we rely on cache expiration and file cleanup
    }

    protected function getUploadFilePath($uploadId, $extension = '')
    {
        // Sanitize upload ID to prevent path traversal
        $uploadId = $this->sanitizeUploadId($uploadId);

        // Sanitize file extension
        $extension = $this->sanitizeFileExtension($extension);

        $filename = $uploadId . ($extension ? '.' . $extension : '');
        return FileUploadConfiguration::path($filename);
    }

    protected function sanitizeUploadId($uploadId)
    {
        // Remove any path traversal attempts
        $uploadId = str_replace(['../', '..\\', '\0', '/', '\\'], '', $uploadId);

        // Only allow alphanumeric, hyphens, and underscores
        $uploadId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $uploadId);

        // Ensure it's not empty after sanitization
        if (empty($uploadId)) {
            throw new \InvalidArgumentException('Invalid upload ID provided');
        }

        return $uploadId;
    }

    protected function sanitizeFileExtension($extension)
    {
        if (empty($extension)) {
            return '';
        }

        // Remove any path traversal attempts and dangerous characters
        $extension = str_replace(['../', '..\\', '\0', '/', '\\'], '', $extension);

        // Only allow alphanumeric characters in extensions
        $extension = preg_replace('/[^a-zA-Z0-9]/', '', $extension);

        // Limit extension length
        $extension = substr($extension, 0, 10);

        return $extension;
    }

    protected function createMetadataFile($filename, $fileInfo)
    {
        $metaFilename = $filename . '.json';
        $metaData = [
            'name' => $fileInfo['name'] ?? 'unknown',
            'type' => $fileInfo['type'] ?? 'application/octet-stream',
            'size' => $fileInfo['size'] ?? 0,
            'hash' => $filename, // Use the generated filename as hash
        ];

        FileUploadConfiguration::storage()->put(
            FileUploadConfiguration::path($metaFilename),
            json_encode($metaData)
        );
    }

    protected function validateAssembledFile($storage, $filePath, $fileInfo)
    {
        try {
            // Get actual file size
            $actualFileSize = $storage->size($filePath);

            // Validate file size against expected size
            $expectedSize = $fileInfo['size'] ?? 0;
            if ($actualFileSize !== $expectedSize) {
                return false;
            }

            // Create a temporary UploadedFile instance for validation
            $realPath = $storage->path($filePath);
            $originalName = $fileInfo['name'] ?? 'unknown';
            $mimeType = $fileInfo['type'] ?? 'application/octet-stream';
            
            // Create a mock uploaded file for validation
            $uploadedFile = new \Illuminate\Http\UploadedFile(
                $realPath,
                $originalName,
                $mimeType,
                null,
                true // test mode
            );

            // Get chunked upload validation rules
            $rules = FileUploadConfiguration::chunkedUploadRules();
            
            // Create validator instance
            $validator = Validator::make(
                ['file' => $uploadedFile],
                ['file' => $rules]
            );

            return !$validator->fails();

        } catch (\Exception $e) {
            return false;
        }
    }
}
