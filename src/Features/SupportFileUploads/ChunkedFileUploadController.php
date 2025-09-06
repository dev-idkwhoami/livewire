<?php

namespace Livewire\Features\SupportFileUploads;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Validator;

class ChunkedFileUploadController implements HasMiddleware
{
    public static array $defaultMiddleware = ['web'];

    public static function middleware()
    {
        $middleware = (array) FileUploadConfiguration::middleware();

        // Prepend the default middleware to the middleware array if it's not already present...
        foreach (array_reverse(static::$defaultMiddleware) as $defaultMiddleware) {
            if (!in_array($defaultMiddleware, $middleware)) {
                array_unshift($middleware, $defaultMiddleware);
            }
        }

        return array_map(fn($middleware) => new Middleware($middleware), $middleware);
    }

    public function handle(Request $request): JsonResponse
    {
        abort_unless($request->hasValidSignature(), 401);

        $validator = Validator::make($request->all(), [
            'upload_id' => 'required|string',
            'chunk_index' => 'required|integer|min:0',
            'chunk_data' => 'required|file',
            'chunk_hash' => 'required|string|size:64', // SHA256 hash is 64 characters
        ]);

        if ($validator->fails()) {
            abort(422);
        }

        $uploadId = $request->input('upload_id');
        $chunkIndex = (int) $request->input('chunk_index');
        $chunkFile = $request->file('chunk_data');
        $chunkHash = $request->input('chunk_hash');

        // Read binary data from uploaded file
        $chunkData = $chunkFile->getContent();

        $result = ChunkedUploadManager::processChunk($uploadId, $chunkIndex, $chunkData, $chunkHash);

        // If we get here, result is either success data or a JsonResponse for hash mismatch
        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        if (isset($result['complete']) && $result['complete']) {
            return response()->json([
                'complete' => true,
                'path' => str_replace(FileUploadConfiguration::path(DIRECTORY_SEPARATOR), '', $result['path'])
            ]);
        }

        return response()->json([
            'progress' => $result['progress'],
            'received' => count($result['received']),
            'total' => $result['total']
        ]);
    }
}
