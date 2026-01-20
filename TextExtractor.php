<?php

/**
 * Text Extractor for PDF, DOCX, and Image files
 * Supports OCR using Tesseract for images and scanned PDFs
 */
class TextExtractor {
    private $uploadDir;
    private $tesseractPath;
    private $pdfToTextPath;
    private $pdftoppmPath;
    
    public function __construct($uploadDir = 'uploads/') {
        $this->uploadDir = rtrim($uploadDir, '/') . '/';
        $this->detectTools();
    }
    
    /**
     * Detect available extraction tools
     */
    private function detectTools() {
        $this->tesseractPath = $this->findCommand('tesseract');
        $this->pdfToTextPath = $this->findCommand('pdftotext');
        $this->pdftoppmPath = $this->findCommand('pdftoppm');
    }
    
    /**
     * Find command path
     */
    private function findCommand($command) {
        $path = trim(shell_exec("which $command 2>/dev/null"));
        return $path ?: null;
    }
    
    /**
     * Extract text from file based on type
     * @param string $filePath Path to the file
     * @param array $options ['ocr' => bool, 'lang' => string]
     * @return array Result with success, text, and method used
     */
    public function extractText($filePath, $options = []) {
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'file_not_found',
                'message' => 'File not found: ' . $filePath
            ];
        }
        
        $useOcr = $options['ocr'] ?? true;
        $lang = $options['lang'] ?? 'eng';
        
        // Detect file type
        $mimeType = mime_content_type($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        try {
            // Route to appropriate extractor
            if ($mimeType === 'application/pdf' || $extension === 'pdf') {
                return $this->extractFromPdf($filePath, $useOcr, $lang);
            } elseif ($this->isDocx($mimeType, $extension)) {
                return $this->extractFromDocx($filePath);
            } elseif ($this->isImage($mimeType, $extension)) {
                return $this->extractFromImage($filePath, $lang);
            } else {
                return [
                    'success' => false,
                    'error' => 'unsupported_type',
                    'message' => "Unsupported file type: $mimeType",
                    'supported_types' => ['PDF', 'DOCX', 'Images (JPG, PNG, TIFF, etc.)']
                ];
            }
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => 'extraction_failed',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Extract text from PDF
     */
    private function extractFromPdf($filePath, $useOcr, $lang) {
        $text = '';
        $method = 'unknown';
        
        // Try pdftotext first (faster for text PDFs)
        if ($this->pdfToTextPath) {
            $output = [];
            $cmd = escapeshellcmd($this->pdfToTextPath) . ' ' . escapeshellarg($filePath) . ' -';
            exec($cmd, $output, $returnCode);
            
            if ($returnCode === 0) {
                $text = implode("\n", $output);
                $method = 'pdftotext';
                
                // If text is empty or too short, try OCR
                if ($useOcr && strlen(trim($text)) < 50) {
                    $ocrResult = $this->extractPdfWithOcr($filePath, $lang);
                    if ($ocrResult['success'] && strlen($ocrResult['text']) > strlen($text)) {
                        return $ocrResult;
                    }
                }
                
                return [
                    'success' => true,
                    'text' => $text,
                    'method' => $method,
                    'char_count' => strlen($text),
                    'word_count' => str_word_count($text)
                ];
            }
        }
        
        // Fallback to OCR if available
        if ($useOcr) {
            return $this->extractPdfWithOcr($filePath, $lang);
        }
        
        return [
            'success' => false,
            'error' => 'no_extraction_tool',
            'message' => 'No PDF extraction tool available. Install pdftotext or tesseract.',
            'install_hint' => 'sudo apt-get install poppler-utils tesseract-ocr'
        ];
    }
    
    /**
     * Extract text from PDF using OCR
     */
    private function extractPdfWithOcr($filePath, $lang) {
        if (!$this->tesseractPath) {
            return [
                'success' => false,
                'error' => 'tesseract_not_found',
                'message' => 'Tesseract OCR not installed',
                'install_hint' => 'sudo apt-get install tesseract-ocr'
            ];
        }
        
        if (!$this->pdftoppmPath) {
            return [
                'success' => false,
                'error' => 'pdftoppm_not_found',
                'message' => 'pdftoppm not installed (required for PDF to image conversion)',
                'install_hint' => 'sudo apt-get install poppler-utils'
            ];
        }
        
        // Create temp directory for images
        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        mkdir($tempDir, 0755, true);
        
        try {
            // Convert PDF pages to images using pdftoppm
            $outputPrefix = $tempDir . '/page';
            $pdftoppmCmd = escapeshellcmd($this->pdftoppmPath) . ' -png -r 300 ' . 
                          escapeshellarg($filePath) . ' ' . escapeshellarg($outputPrefix) . ' 2>&1';
            
            exec($pdftoppmCmd, $convertOutput, $convertReturn);
            
            if ($convertReturn !== 0) {
                throw new Exception('Failed to convert PDF to images: ' . implode("\n", $convertOutput));
            }
            
            // Get all generated images (pdftoppm uses format: page-1.png, page-2.png, etc.)
            $images = glob($tempDir . '/page-*.png');
            if (empty($images)) {
                throw new Exception('No images generated from PDF');
            }
            
            // Sort images by page number
            natsort($images);
            
            // Extract text from each page
            $allText = [];
            foreach ($images as $imagePath) {
                $pageNum = basename($imagePath);
                $outputBase = $tempDir . '/' . pathinfo($imagePath, PATHINFO_FILENAME);
                
                $tesseractCmd = escapeshellcmd($this->tesseractPath) . ' ' . 
                               escapeshellarg($imagePath) . ' ' . 
                               escapeshellarg($outputBase) . ' -l ' . 
                               escapeshellarg($lang) . ' 2>&1';
                
                exec($tesseractCmd, $tesseractOutput, $tesseractReturn);
                
                $txtFile = $outputBase . '.txt';
                if (file_exists($txtFile)) {
                    $pageText = file_get_contents($txtFile);
                    if (trim($pageText)) {
                        $allText[] = "=== Page " . (count($allText) + 1) . " ===\n" . $pageText;
                    }
                }
            }
            
            // Clean up temp files
            array_map('unlink', glob($tempDir . '/*'));
            rmdir($tempDir);
            
            $fullText = implode("\n\n", $allText);
            
            return [
                'success' => true,
                'text' => $fullText,
                'method' => 'tesseract_ocr',
                'pages_processed' => count($images),
                'char_count' => strlen($fullText),
                'word_count' => str_word_count($fullText),
                'language' => $lang
            ];
            
        } catch (Exception $e) {
            // Clean up on error
            if (is_dir($tempDir)) {
                array_map('unlink', glob($tempDir . '/*'));
                rmdir($tempDir);
            }
            throw $e;
        }
    }
    
    /**
     * Extract text from DOCX
     */
    private function extractFromDocx($filePath) {
        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'error' => 'zip_not_available',
                'message' => 'PHP ZIP extension not available',
                'install_hint' => 'sudo apt-get install php-zip'
            ];
        }
        
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return [
                'success' => false,
                'error' => 'invalid_docx',
                'message' => 'Unable to open DOCX file. File may be corrupted.'
            ];
        }
        
        // Extract text from document.xml
        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();
        
        if ($xmlContent === false) {
            return [
                'success' => false,
                'error' => 'invalid_docx_structure',
                'message' => 'DOCX file structure is invalid or corrupted.'
            ];
        }
        
        // Parse XML and extract text from <w:t> tags
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            return [
                'success' => false,
                'error' => 'xml_parse_failed',
                'message' => 'Failed to parse DOCX XML content.'
            ];
        }
        
        // Register namespace
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        
        // Extract text with paragraph structure preserved
        $paragraphs = $xml->xpath('//w:p');
        $textLines = [];
        
        foreach ($paragraphs as $paragraph) {
            // Get all text nodes within this paragraph
            $paragraph->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $textNodes = $paragraph->xpath('.//w:t');
            
            if ($textNodes) {
                $paraText = '';
                foreach ($textNodes as $textNode) {
                    $paraText .= (string)$textNode;
                }
                $paraText = trim($paraText);
                if ($paraText !== '') {
                    $textLines[] = $paraText;
                }
            }
        }
        
        $text = implode("\n", $textLines);
        
        return [
            'success' => true,
            'text' => $text,
            'method' => 'docx_xml_extraction',
            'char_count' => strlen($text),
            'word_count' => str_word_count($text),
            'line_count' => count($textLines)
        ];
    }
    
    /**
     * Extract text from image using OCR
     */
    private function extractFromImage($filePath, $lang) {
        if (!$this->tesseractPath) {
            return [
                'success' => false,
                'error' => 'tesseract_not_found',
                'message' => 'Tesseract OCR not installed',
                'install_hint' => 'sudo apt-get install tesseract-ocr'
            ];
        }
        
        $outputBase = sys_get_temp_dir() . '/ocr_' . uniqid();
        
        $cmd = escapeshellcmd($this->tesseractPath) . ' ' . 
               escapeshellarg($filePath) . ' ' . 
               escapeshellarg($outputBase) . ' -l ' . 
               escapeshellarg($lang) . ' 2>&1';
        
        exec($cmd, $output, $returnCode);
        
        $txtFile = $outputBase . '.txt';
        
        if ($returnCode !== 0 || !file_exists($txtFile)) {
            return [
                'success' => false,
                'error' => 'ocr_failed',
                'message' => 'Tesseract OCR failed: ' . implode("\n", $output)
            ];
        }
        
        $text = file_get_contents($txtFile);
        unlink($txtFile);
        
        return [
            'success' => true,
            'text' => $text,
            'method' => 'tesseract_ocr',
            'char_count' => strlen($text),
            'word_count' => str_word_count($text),
            'language' => $lang
        ];
    }
    
    /**
     * Check if file is DOCX
     */
    private function isDocx($mimeType, $extension) {
        return $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' 
               || $extension === 'docx';
    }
    
    /**
     * Check if file is an image
     */
    private function isImage($mimeType, $extension) {
        $imageMimes = ['image/jpeg', 'image/png', 'image/tiff', 'image/bmp', 'image/gif'];
        $imageExts = ['jpg', 'jpeg', 'png', 'tiff', 'tif', 'bmp', 'gif'];
        
        return in_array($mimeType, $imageMimes) || in_array($extension, $imageExts);
    }
    
    /**
     * Get system capabilities
     */
    public function getCapabilities() {
        return [
            'tesseract' => $this->tesseractPath ? true : false,
            'pdftotext' => $this->pdfToTextPath ? true : false,
            'pdftoppm' => $this->pdftoppmPath ? true : false,
            'zip_extension' => class_exists('ZipArchive'),
            'supported_formats' => [
                'pdf' => $this->pdfToTextPath || ($this->tesseractPath && $this->pdftoppmPath),
                'docx' => class_exists('ZipArchive'),
                'images' => $this->tesseractPath
            ]
        ];
    }
}
