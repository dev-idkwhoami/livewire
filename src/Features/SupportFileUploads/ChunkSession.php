<?php

namespace Livewire\Features\SupportFileUploads;

use Illuminate\Support\Facades\Cache;

class ChunkSession
{
    protected $uploadId;
    protected $fileInfo;
    protected $chunkSize;
    protected $totalChunks;
    protected $receivedChunks = [];
    protected $complete = false;
    protected $finalPath = null;
    protected $createdAt;
    protected $fileHash = null;

    public function __construct($uploadId, $fileInfo, $chunkSize, $totalChunks)
    {
        $this->uploadId = $uploadId;
        $this->fileInfo = $fileInfo;
        $this->chunkSize = $chunkSize;
        $this->totalChunks = $totalChunks;
        $this->fileHash = $fileInfo['hash'] ?? null;
        $this->createdAt = time();
    }

    public static function create($uploadId, $fileInfo, $chunkSize, $totalChunks)
    {
        $session = new static($uploadId, $fileInfo, $chunkSize, $totalChunks);
        $session->save();
        return $session;
    }

    public static function load($uploadId)
    {
        $data = Cache::get(static::getCacheKey($uploadId));

        if (! $data) {
            return null;
        }

        $session = new static(
            $data['uploadId'],
            $data['fileInfo'],
            $data['chunkSize'],
            $data['totalChunks']
        );

        $session->receivedChunks = $data['receivedChunks'] ?? [];
        $session->complete = $data['complete'] ?? false;
        $session->finalPath = $data['finalPath'] ?? null;
        $session->createdAt = $data['createdAt'] ?? time();
        $session->fileHash = $data['fileHash'] ?? null;

        return $session;
    }

    public static function findByFileHash($fileHash)
    {
        // Since we don't have a direct way to search cache by value,
        // we'll use a secondary cache key for file hash lookups
        $uploadId = Cache::get(static::getFileHashCacheKey($fileHash));

        if ($uploadId) {
            return static::load($uploadId);
        }

        return null;
    }

    public function save()
    {
        $data = [
            'uploadId' => $this->uploadId,
            'fileInfo' => $this->fileInfo,
            'chunkSize' => $this->chunkSize,
            'totalChunks' => $this->totalChunks,
            'receivedChunks' => $this->receivedChunks,
            'complete' => $this->complete,
            'finalPath' => $this->finalPath,
            'createdAt' => $this->createdAt,
            'fileHash' => $this->fileHash,
        ];

        $ttl = FileUploadConfiguration::chunkedSessionTimeout();
        Cache::put(static::getCacheKey($this->uploadId), $data, $ttl);

        // Also save file hash mapping for resumable uploads
        if ($this->fileHash) {
            Cache::put(static::getFileHashCacheKey($this->fileHash), $this->uploadId, $ttl);
        }
    }

    public function markChunkReceived($chunkIndex)
    {
        // Retry approach with exponential backoff to handle race conditions
        $maxRetries = config('livewire.chunked_uploads.retry_attempts', 3);
        $attempt = 0;

        while ($attempt < $maxRetries) {
            // Load fresh session data
            $latestData = Cache::get(static::getCacheKey($this->uploadId));

            if (!$latestData) {
                break; // Session doesn't exist
            }

            $receivedChunks = $latestData['receivedChunks'] ?? [];

            if (in_array($chunkIndex, $receivedChunks)) {
                // Chunk already marked, update local instance and return
                $this->receivedChunks = $receivedChunks;
                return;
            }

            // Add chunk to received chunks
            $newReceivedChunks = $receivedChunks;
            $newReceivedChunks[] = $chunkIndex;
            sort($newReceivedChunks);

            // Try to update with conditional put (compare and swap)
            $latestData['receivedChunks'] = $newReceivedChunks;
            $ttl = FileUploadConfiguration::chunkedSessionTimeout();

            // Simple retry mechanism - if another process updated between our read and write,
            // we'll see different data on next iteration and retry
            Cache::put(static::getCacheKey($this->uploadId), $latestData, $ttl);

            // Verify the update succeeded by reading back
            $verifyData = Cache::get(static::getCacheKey($this->uploadId));
            if ($verifyData && in_array($chunkIndex, $verifyData['receivedChunks'] ?? [])) {
                // Success - update local instance
                $this->receivedChunks = $verifyData['receivedChunks'];
                return;
            }

            // Failed, retry with backoff
            $attempt++;
            if ($attempt < $maxRetries) {
                usleep(pow(2, $attempt) * 1000); // Exponential backoff: 2ms, 4ms, 8ms, 16ms
            }
        }
    }

    public function isComplete()
    {
        return count($this->receivedChunks) === $this->totalChunks;
    }

    public function markComplete($finalPath)
    {
        $this->complete = true;
        $this->finalPath = $finalPath;
        $this->save();
    }

    public function getProgress()
    {
        return $this->totalChunks > 0 ? (count($this->receivedChunks) / $this->totalChunks) * 100 : 0;
    }

    public function getReceivedChunks()
    {
        return $this->receivedChunks;
    }

    public function getTotalChunks()
    {
        return $this->totalChunks;
    }

    public function getFileInfo()
    {
        return $this->fileInfo;
    }

    public function getChunkSize()
    {
        return $this->chunkSize;
    }

    public function getUploadId()
    {
        return $this->uploadId;
    }

    public function getFinalPath()
    {
        return $this->finalPath;
    }

    public function delete()
    {
        Cache::forget(static::getCacheKey($this->uploadId));

        if ($this->fileHash) {
            Cache::forget(static::getFileHashCacheKey($this->fileHash));
        }
    }

    public function isExpired()
    {
        $timeout = FileUploadConfiguration::chunkedSessionTimeout();
        return (time() - $this->createdAt) > $timeout;
    }

    protected static function getCacheKey($uploadId)
    {
        return "livewire_chunk_session_{$uploadId}";
    }

    protected static function getFileHashCacheKey($fileHash)
    {
        return "livewire_chunk_file_hash_{$fileHash}";
    }
}
