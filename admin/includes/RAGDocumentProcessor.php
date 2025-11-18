<?php
class RAGDocumentProcessor {
    private $conn;
    private $chunk_size = 1000;
    private $chunk_overlap = 200;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    public function processAllDocumentsForRAG() {
        $processed = 0;
        
        // Process ordinances
        $processed += $this->processOrdinancesForRAG();
        
        // Process resolutions
        $processed += $this->processResolutionsForRAG();
        
        // Process minutes
        $processed += $this->processMinutesForRAG();
        
        // Process OCR content
        $processed += $this->processOCRContentForRAG();
        
        return $processed;
    }
    
    private function processOrdinancesForRAG() {
        $sql = "SELECT id, title, COALESCE(ocr_content, content, description) as content, 
                       reference_number, ordinance_number, date_issued, created_at
                FROM ordinances 
                WHERE status = 'Active' 
                AND id NOT IN (SELECT document_id FROM rag_processing_status WHERE document_type = 'ordinance' AND processing_status = 'completed')";
        
        $result = $this->conn->query($sql);
        $processed = 0;
        
        while ($doc = $result->fetch_assoc()) {
            if ($this->processSingleDocument($doc, 'ordinance')) {
                $processed++;
            }
        }
        
        return $processed;
    }
    
    private function processResolutionsForRAG() {
        $sql = "SELECT id, title, COALESCE(content, description) as content, 
                       reference_number, resolution_number, date_issued, created_at
                FROM resolutions 
                WHERE status = 'Active' 
                AND id NOT IN (SELECT document_id FROM rag_processing_status WHERE document_type = 'resolution' AND processing_status = 'completed')";
        
        $result = $this->conn->query($sql);
        $processed = 0;
        
        while ($doc = $result->fetch_assoc()) {
            if ($this->processSingleDocument($doc, 'resolution')) {
                $processed++;
            }
        }
        
        return $processed;
    }
    
    private function processMinutesForRAG() {
        $sql = "SELECT id, title, content, 
                       reference_number, session_number, meeting_date as date_issued, created_at
                FROM minutes_of_meeting 
                WHERE status = 'Active' 
                AND id NOT IN (SELECT document_id FROM rag_processing_status WHERE document_type = 'minute' AND processing_status = 'completed')";
        
        $result = $this->conn->query($sql);
        $processed = 0;
        
        while ($doc = $result->fetch_assoc()) {
            if ($this->processSingleDocument($doc, 'minute')) {
                $processed++;
            }
        }
        
        return $processed;
    }
    
    private function processOCRContentForRAG() {
        $sql = "SELECT doc.id, 
                       COALESCE(o.title, r.title, m.title, 'OCR Document') as title,
                       doc.ocr_content as content,
                       COALESCE(o.reference_number, r.reference_number, m.reference_number, 'N/A') as reference_number,
                       doc.document_type,
                       doc.created_at
                FROM document_ocr_content doc
                LEFT JOIN ordinances o ON doc.document_id = o.id AND doc.document_type = 'ordinance'
                LEFT JOIN resolutions r ON doc.document_id = r.id AND doc.document_type = 'resolution'  
                LEFT JOIN minutes_of_meeting m ON doc.document_id = m.id AND doc.document_type = 'meeting_minutes'
                WHERE doc.id NOT IN (SELECT document_id FROM rag_processing_status WHERE document_type = doc.document_type AND processing_status = 'completed')";
        
        $result = $this->conn->query($sql);
        $processed = 0;
        
        while ($doc = $result->fetch_assoc()) {
            if ($this->processSingleDocument($doc, $doc['document_type'] . '_ocr')) {
                $processed++;
            }
        }
        
        return $processed;
    }
    
    private function processSingleDocument($document, $document_type) {
        try {
            // Mark as processing
            $this->updateProcessingStatus($document['id'], $document_type, 'processing');
            
            // Extract and clean text
            $clean_text = $this->cleanDocumentText($document['content']);
            
            if (empty(trim($clean_text))) {
                $this->updateProcessingStatus($document['id'], $document_type, 'failed', 'Empty content');
                return false;
            }
            
            // Split into chunks
            $chunks = $this->splitTextIntoChunks($clean_text);
            
            // Store chunks in database
            $chunks_stored = $this->storeDocumentChunks($document, $document_type, $chunks);
            
            // Mark as completed
            $this->updateProcessingStatus($document['id'], $document_type, 'completed', '', $chunks_stored);
            
            return true;
            
        } catch (Exception $e) {
            $this->updateProcessingStatus($document['id'], $document_type, 'failed', $e->getMessage());
            error_log("RAG Processing Error for {$document_type} ID {$document['id']}: " . $e->getMessage());
            return false;
        }
    }
    
    private function cleanDocumentText($text) {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove special characters but keep basic punctuation
        $text = preg_replace('/[^\w\s\.\,\-\:\;\(\)\@\#\$\%\&\*\+\=\[\]\{\}\|\<\>\"\'\?\!]/', '', $text);
        
        // Trim and return
        return trim($text);
    }
    
    private function splitTextIntoChunks($text) {
        $chunks = [];
        $text_length = strlen($text);
        $start = 0;
        $chunk_index = 0;
        
        while ($start < $text_length) {
            $chunk = substr($text, $start, $this->chunk_size);
            
            // Try to break at sentence end for better chunks
            $last_period = strrpos($chunk, '.');
            $last_exclamation = strrpos($chunk, '!');
            $last_question = strrpos($chunk, '?');
            
            $break_point = max($last_period, $last_exclamation, $last_question);
            
            if ($break_point !== false && $break_point > $this->chunk_size * 0.5) {
                $chunk = substr($chunk, 0, $break_point + 1);
            }
            
            $chunks[] = [
                'text' => trim($chunk),
                'index' => $chunk_index
            ];
            
            $start += strlen($chunk) - $this->chunk_overlap;
            $chunk_index++;
            
            // Ensure we make progress
            if ($start >= $text_length) break;
            if ($start == $start - strlen($chunk) + $this->chunk_overlap) {
                $start += 100; // Force progress
            }
        }
        
        return $chunks;
    }
    
    private function storeDocumentChunks($document, $document_type, $chunks) {
        $stmt = $this->conn->prepare("
            INSERT INTO document_chunks 
            (document_id, document_type, chunk_text, chunk_index, metadata) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $chunks_stored = 0;
        
        foreach ($chunks as $chunk) {
            $metadata = json_encode([
                'title' => $document['title'] ?? 'Unknown',
                'reference_number' => $document['reference_number'] ?? 'N/A',
                'document_number' => $document['ordinance_number'] ?? $document['resolution_number'] ?? $document['session_number'] ?? 'N/A',
                'date_issued' => $document['date_issued'] ?? null,
                'chunk_size' => strlen($chunk['text'])
            ]);
            
            $stmt->bind_param("issis", 
                $document['id'], 
                $document_type, 
                $chunk['text'], 
                $chunk['index'], 
                $metadata
            );
            
            if ($stmt->execute()) {
                $chunks_stored++;
            }
        }
        
        return $chunks_stored;
    }
    
    private function updateProcessingStatus($document_id, $document_type, $status, $error_message = '', $chunks_count = 0) {
        $sql = "INSERT INTO rag_processing_status 
                (document_id, document_type, processing_status, chunks_count, error_message, last_processed_at) 
                VALUES (?, ?, ?, ?, ?, NOW()) 
                ON DUPLICATE KEY UPDATE 
                processing_status = VALUES(processing_status),
                chunks_count = VALUES(chunks_count),
                error_message = VALUES(error_message),
                last_processed_at = VALUES(last_processed_at)";
        
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("issis", $document_id, $document_type, $status, $chunks_count, $error_message);
        $stmt->execute();
    }
    
    public function getRAGStatistics() {
        $stats = [];
        
        // Total chunks
        $result = $this->conn->query("SELECT COUNT(*) as total_chunks FROM document_chunks");
        $stats['total_chunks'] = $result->fetch_assoc()['total_chunks'];
        
        // Chunks by document type
        $result = $this->conn->query("
            SELECT document_type, COUNT(*) as count 
            FROM document_chunks 
            GROUP BY document_type
        ");
        $stats['chunks_by_type'] = $result->fetch_all(MYSQLI_ASSOC);
        
        // Processing status
        $result = $this->conn->query("
            SELECT processing_status, COUNT(*) as count 
            FROM rag_processing_status 
            GROUP BY processing_status
        ");
        $stats['processing_status'] = $result->fetch_all(MYSQLI_ASSOC);
        
        return $stats;
    }
}
?>