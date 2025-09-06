import { getCsrfToken } from '@/utils';

export class ChunkedUploadManager {
    constructor(component, uploadStrategies, property, chunkUrl) {
        this.component = component;
        this.uploadStrategies = uploadStrategies;
        this.property = property;
        this.chunkUrl = chunkUrl;
        this.chunkSessions = new Map();
        this.uploadPromises = [];
        this.cancelled = false;
    }

    async startUploads(files, finishCallback, errorCallback, progressCallback, cancelledCallback) {
        this.finishCallback = finishCallback;
        this.errorCallback = errorCallback;
        this.progressCallback = progressCallback;
        this.cancelledCallback = cancelledCallback;
        this.originalFiles = files;
        this.completedFiles = 0;
        this.totalFiles = this.uploadStrategies.length;
        this.isMultiple = files.length > 1;
        this.completedUploadIds = [];

        try {
            for (let i = 0; i < this.uploadStrategies.length; i++) {
                const strategy = this.uploadStrategies[i];
                const file = files[strategy.index];

                if (strategy.strategy === 'chunked') {
                    await this.startChunkedUpload(strategy, file);
                } else {
                    // Handle normal uploads through existing mechanism
                    await this.startNormalUpload(strategy, file);
                }
            }
        } catch (error) {
            this.handleError(error);
        }
    }

    async startChunkedUpload(strategy, file) {
        const session = {
            uploadId: strategy.uploadId,
            file: file,
            chunkSize: strategy.chunkSize,
            totalChunks: strategy.totalChunks,
            uploadedChunks: 0,
            failedChunks: new Set(),
            retryAttempts: new Map()
        };

        this.chunkSessions.set(strategy.uploadId, session);

        // Create chunks and upload them (skip already received chunks for resumable uploads)
        const chunkPromises = [];
        const receivedChunks = new Set(strategy.receivedChunks || []);
        
        for (let chunkIndex = 0; chunkIndex < strategy.totalChunks; chunkIndex++) {
            if (receivedChunks.has(chunkIndex)) {
                // Skip already uploaded chunks
                session.uploadedChunks++;
                continue;
            }
            
            const chunkPromise = this.uploadChunk(session, chunkIndex);
            chunkPromises.push(chunkPromise);
        }

        this.uploadPromises.push(...chunkPromises);
        
        try {
            await Promise.all(chunkPromises);
            
            // Track this completed upload
            this.completedUploadIds.push(strategy.uploadId);
            this.completedFiles++;
            
            // If all files are complete, finish the entire batch
            if (this.completedFiles === this.totalFiles) {
                this.component.$wire.call('_finishChunkedUpload', this.property, this.completedUploadIds, this.isMultiple);
                // Don't call finish callback here - let the server response handle it via upload:finished
            }
        } catch (error) {
            this.handleChunkedUploadError(session, error);
        }
    }

    async uploadChunk(session, chunkIndex) {
        if (this.cancelled) return;

        const start = chunkIndex * session.chunkSize;
        const end = Math.min(start + session.chunkSize, session.file.size);
        const chunk = session.file.slice(start, end);
        
        // Calculate chunk hash
        const chunkArrayBuffer = await chunk.arrayBuffer();
        const chunkHash = await this.calculateSHA256(chunkArrayBuffer);

        const formData = new FormData();
        formData.append('upload_id', session.uploadId);
        formData.append('chunk_index', chunkIndex.toString());
        formData.append('chunk_data', chunk); // Send as binary blob, not base64
        formData.append('chunk_hash', chunkHash);

        const headers = {
            'Accept': 'application/json',
        };

        const csrfToken = getCsrfToken();
        if (csrfToken) headers['X-CSRF-TOKEN'] = csrfToken;

        try {
            const response = await fetch(this.chunkUrl, {
                method: 'POST',
                body: formData,
                headers: headers
            });

            const result = await response.json();

            if (!response.ok) {
                if (response.status === 409 && result.chunkIndex === chunkIndex) {
                    // Hash mismatch - retry
                    return this.retryChunk(session, chunkIndex);
                }
                throw new Error(result.error || 'Upload failed');
            }

            // Update progress
            session.uploadedChunks++;
            this.updateProgress(session);

            return result;
        } catch (error) {
            return this.retryChunk(session, chunkIndex, error);
        }
    }

    async retryChunk(session, chunkIndex, error = null) {
        const retryKey = `${session.uploadId}-${chunkIndex}`;
        const currentRetries = session.retryAttempts.get(retryKey) || 0;
        const maxRetries = 3; // This should come from config

        if (currentRetries >= maxRetries) {
            throw new Error(`Chunk ${chunkIndex} failed after ${maxRetries} retries: ${error?.message || 'Unknown error'}`);
        }

        session.retryAttempts.set(retryKey, currentRetries + 1);
        
        // Exponential backoff
        const delay = Math.pow(2, currentRetries) * 1000;
        await this.sleep(delay);

        return this.uploadChunk(session, chunkIndex);
    }

    async startNormalUpload(strategy, file) {
        // This would delegate to the existing upload mechanism
        // For now, we'll just resolve since normal uploads are handled elsewhere
        return Promise.resolve();
    }

    updateProgress(session) {
        const progress = Math.round((session.uploadedChunks / session.totalChunks) * 100);
        
        if (this.progressCallback) {
            this.progressCallback({ 
                loaded: session.uploadedChunks * session.chunkSize, 
                total: session.file.size,
                detail: { progress }
            });
        }
    }

    handleError(error) {
        console.error('Chunked upload error:', error);
        if (this.errorCallback) {
            this.errorCallback();
        }
    }

    handleChunkedUploadError(session, error) {
        session.failedChunks.add(error);
        this.handleError(error);
    }

    cancel() {
        this.cancelled = true;
        
        // Cancel all ongoing requests
        this.uploadPromises.forEach(promise => {
            if (promise.abort) {
                promise.abort();
            }
        });

        if (this.cancelledCallback) {
            this.cancelledCallback();
        }
    }

    async calculateSHA256(arrayBuffer) {
        const hashBuffer = await crypto.subtle.digest('SHA-256', arrayBuffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
    }

    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}