## Implementation approach

### Multi File Chunk Upload (MFCU)
The general idea is each chunk, sent by the client, is stored in a temporary folder as its own chunk file.
When the server detects that all chunks have been sent, the server reassembles all chunks and deletes the temporary files.

## Definition base functionality

- The client initializes the upload.
- The server detects when a file is larger than a configurable threshold and automatically switches to chunked file uploads.
- The server responds with the intent to use chunked or normal uploads and a unique upload id (ULID)
- The client sends the chunks of each file in parallel to the server combined with the corresponding upload id, the chunks size, offset, index, the total number of chunks 
- The server stores the chunks in the final file position based and responds with the progress (how many chunks have been written of the total number of chunks) of the upload.
- On the upload of the final chunk, the server, based on initially sent metadata,
  detects completion
- The server responds with the final file path/s, else it responds with an error.

## Definition extended functionality

### Concurrent chunk uploads
- The client sends multiple chunks to the server in parallel, each including the upload id and metadata such as the chunk index, offset, size, and total number of chunks.
- The server stores the chunks.
- The server responds with the progress of the upload,
- Chunks may arrive out of order. Completion is detected only when all expected bytes are written and verified; the client does not signal completion.
- The final chunk may have a different (usually smaller) size than previous chunks and must be validated against its declared size and offset.

### Error Handling & Retry Logic
- The client hashes every chunk and sends that hash with the chunk to the server.
- The server hashes the chunk and compares it with the hash sent by the client.
- If the hashes don't match, the server responds with an error status code (e.g., 409 Conflict) and the chunk index to retry.
- The client counts the number of retries and after a threshold cancels all upload requests for this session.

### Resumable uploads
- The server tracks the progress of uploaded chunks and stores it with the file hash.
- Should the upload be interrupted, the initial request will check if an upload session with the same hash exists.
- If it does, the server will respond with the last received chunk, else it continues like normal.
- The client will resend the last received chunk even if it was completed to prevent corruption if the last chunk was only partially received or written.
