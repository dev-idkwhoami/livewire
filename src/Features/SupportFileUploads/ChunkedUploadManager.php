<?php

namespace Livewire\Features\SupportFileUploads;

use Illuminate\Support\Str;

class ChunkedUploadManager
{
    public static function shouldChunkFile($fileSizeBytes)
    {
        if (! static::isEnabled()) {
            return ['shouldChunk' => false];
        }
        
        // Validate storage type - chunked uploads only work with local storage
        if (! static::isLocalStorage()) {
            throw new \RuntimeException('Chunked uploads are only supported with local storage. Current storage type is not supported.');
        }

        $chunkInfo = static::calculateChunkSize($fileSizeBytes);
        
        if (! $chunkInfo['shouldChunk']) {
            return ['shouldChunk' => false];
        }

        return [
            'shouldChunk' => true,
            'chunkSize' => $chunkInfo['chunkSize'],
            'totalChunks' => $chunkInfo['totalChunks'],
            'uploadId' => static::generateSecureUploadId(),
        ];
    }

    public static function calculateChunkSize($fileSizeBytes)
    {
        $maxChunkSizeKB = static::maxChunkSizeKB();
        $minChunkSizeKB = 4096; // 4MB minimum
        
        // Sigmoid curve: 4096 + (max-4096) / (1 + exp(-(log(fileSize) - 20.7944)))
        $chunkSizeKB = $minChunkSizeKB + ($maxChunkSizeKB - $minChunkSizeKB) / (1 + exp(-(log($fileSizeBytes) - 20.7944)));
        $chunkSizeBytes = (int) ($chunkSizeKB * 1024);
        $totalChunks = (int) ceil($fileSizeBytes / $chunkSizeBytes);
        
        // Validate minimum chunks requirement
        $minChunks = static::minChunks();
        if ($totalChunks < $minChunks) {
            return ['shouldChunk' => false];
        }
        
        return [
            'shouldChunk' => true,
            'chunkSize' => $chunkSizeBytes,
            'totalChunks' => $totalChunks,
        ];
    }

    public static function processFileInfos($fileInfos, $isMultiple)
    {
        $results = [];
        
        foreach ($fileInfos as $index => $fileInfo) {
            $fileSize = $fileInfo['size'];
            $chunkDecision = static::shouldChunkFile($fileSize);
            
            if ($chunkDecision['shouldChunk']) {
                $results[] = [
                    'index' => $index,
                    'name' => $fileInfo['name'],
                    'strategy' => 'chunked',
                    'uploadId' => $chunkDecision['uploadId'],
                    'chunkSize' => $chunkDecision['chunkSize'],
                    'totalChunks' => $chunkDecision['totalChunks'],
                    'fileSize' => $fileSize,
                ];
            } else {
                $results[] = [
                    'index' => $index,
                    'name' => $fileInfo['name'],
                    'strategy' => 'normal',
                ];
            }
        }
        
        return $results;
    }

    public static function createChunkSession($uploadId, $fileInfo, $chunkSize, $totalChunks)
    {
        return ChunkSession::create($uploadId, $fileInfo, $chunkSize, $totalChunks);
    }

    public static function findResumableSession($fileHash)
    {
        // Look for existing sessions with the same file hash
        return ChunkSession::findByFileHash($fileHash);
    }

    public static function processChunk($uploadId, $chunkIndex, $chunkData, $chunkHash)
    {
        $session = ChunkSession::load($uploadId);
        
        if (! $session) {
            abort(404);
        }

        // Verify chunk hash
        $computedHash = hash('sha256', $chunkData);
        if ($computedHash !== $chunkHash) {
            return response()->json(['chunkIndex' => $chunkIndex], 409);
        }

        // Store chunk
        $storage = new ChunkedUploadStorage();
        $fileInfo = $session->getFileInfo();
        $originalName = $fileInfo['name'] ?? '';
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        
        $chunkStored = $storage->storeChunk($uploadId, $chunkIndex, $chunkData, $session->getChunkSize(), $extension);
        
        if (! $chunkStored) {
            abort(500);
        }

        // Update session progress
        $session->markChunkReceived($chunkIndex);
        
        // Check if upload is complete
        if ($session->isComplete() && !$session->getFinalPath()) {
            // Validate total expected file size before assembly
            if (!static::validateTotalFileSize($session->getFileInfo())) {
                abort(413);
            }
            
            $finalPath = $storage->assembleFile($uploadId, $session->getFileInfo());
            
            if ($finalPath) {
                $session->markComplete($finalPath);
                return ['complete' => true, 'path' => $finalPath];
            } else {
                abort(500);
            }
        }

        return [
            'progress' => $session->getProgress(),
            'received' => $session->getReceivedChunks(),
            'total' => $session->getTotalChunks(),
        ];
    }

    protected static function isEnabled()
    {
        return FileUploadConfiguration::isChunkedUploadsEnabled();
    }

    protected static function maxChunkSizeKB()
    {
        return FileUploadConfiguration::maxChunkSizeKB();
    }

    protected static function minChunks()
    {
        return FileUploadConfiguration::minChunks();
    }

    protected static function generateSecureUploadId()
    {
        // Generate cryptographically secure upload ID
        // Using combination of secure random bytes and timestamp for uniqueness
        $randomBytes = random_bytes(32);
        $timestamp = microtime(true);
        $combined = $randomBytes . $timestamp;
        
        return hash('sha256', $combined);
    }

    public static function validateTotalFileSize($fileInfo)
    {
        $fileSize = $fileInfo['size'] ?? 0;
        
        // Get chunked upload validation rules (separate from regular upload rules)
        $rules = FileUploadConfiguration::chunkedUploadRules();
        $maxSizeRule = null;
        
        foreach ($rules as $rule) {
            if (is_string($rule) && str_starts_with($rule, 'max:')) {
                $maxSizeRule = (int) substr($rule, 4);
                break;
            } elseif (is_array($rule) && isset($rule['max'])) {
                $maxSizeRule = (int) $rule['max'];
                break;
            }
        }
        
        // Check if file size exceeds the chunked upload maximum (convert KB to bytes)
        if ($maxSizeRule && $fileSize > ($maxSizeRule * 1024)) {
            return false;
        }
        
        return true;
    }

    protected static function isLocalStorage()
    {
        // Check if we're using a local storage driver
        $storage = FileUploadConfiguration::storage();
        
        // If the storage has a 'path' method, it's likely local storage
        if (method_exists($storage, 'path')) {
            return true;
        }
        
        // Check the driver type from configuration
        $diskConfig = FileUploadConfiguration::diskConfig();
        $driver = $diskConfig['driver'] ?? 'local';
        
        // Only allow local driver for chunked uploads
        return $driver === 'local';
    }
}