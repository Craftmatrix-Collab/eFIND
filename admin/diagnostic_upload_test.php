<?php
/**
 * COMPREHENSIVE UPLOAD DIAGNOSTIC TOOL
 * Tests: Resolutions, Executive Orders, and Meeting Minutes upload functionality
 * Date: 2026-02-16
 */

header('Content-Type: text/html; charset=utf-8');

// Color constants for output
define('COLOR_SUCCESS', '#28a745');
define('COLOR_ERROR', '#dc3545');
define('COLOR_WARNING', '#ffc107');
define('COLOR_INFO', '#17a2b8');

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Diagnostic Tool - eFIND</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }
        .diagnostic-container {
            max-width: 1200px;
            margin: 0 auto;
            background: #252526;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
        }
        .header {
            text-align: center;
            color: #4ec9b0;
            margin-bottom: 30px;
            font-size: 24px;
            font-weight: bold;
        }
        .test-section {
            background: #2d2d30;
            border-left: 4px solid #4ec9b0;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        .test-result {
            padding: 10px;
            margin: 5px 0;
            border-radius: 4px;
            font-size: 14px;
        }
        .success { background: rgba(40, 167, 69, 0.2); border-left: 4px solid #28a745; }
        .error { background: rgba(220, 53, 69, 0.2); border-left: 4px solid #dc3545; }
        .warning { background: rgba(255, 193, 7, 0.2); border-left: 4px solid #ffc107; }
        .info { background: rgba(23, 162, 184, 0.2); border-left: 4px solid #17a2b8; }
        .code-block {
            background: #1e1e1e;
            border: 1px solid #3e3e42;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            margin: 10px 0;
            color: #ce9178;
        }
        table {
            width: 100%;
            margin: 10px 0;
        }
        table td {
            padding: 8px;
            border-bottom: 1px solid #3e3e42;
        }
        table td:first-child {
            color: #569cd6;
            font-weight: bold;
            width: 250px;
        }
        .icon-pass { color: #28a745; }
        .icon-fail { color: #dc3545; }
        .icon-warn { color: #ffc107; }
    </style>
</head>
<body>
    <div class="diagnostic-container">
        <div class="header">
            <i class="fas fa-stethoscope"></i> UPLOAD DIAGNOSTIC TOOL
        </div>

        <?php

        /**
         * Test 1: File Existence Check
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-file-code"></i> TEST 1: File Existence</h4>';
        
        $files = [
            'executive_orders.php' => __DIR__ . '/executive_orders.php',
            'resolutions.php' => __DIR__ . '/resolutions.php',
            'add_documents.php' => __DIR__ . '/add_documents.php'
        ];
        
        foreach ($files as $name => $path) {
            if (file_exists($path)) {
                $size = number_format(filesize($path) / 1024, 2);
                echo "<div class='test-result success'><i class='fas fa-check-circle icon-pass'></i> <strong>$name</strong> exists ({$size} KB)</div>";
            } else {
                echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> <strong>$name</strong> NOT FOUND!</div>";
            }
        }
        echo '</div>';

        /**
         * Test 2: PHP Configuration
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-cog"></i> TEST 2: PHP Upload Configuration</h4>';
        
        $phpConfig = [
            'file_uploads' => ini_get('file_uploads'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'max_input_time' => ini_get('max_input_time'),
            'memory_limit' => ini_get('memory_limit')
        ];
        
        echo '<table>';
        foreach ($phpConfig as $key => $value) {
            $icon = $value ? 'check-circle icon-pass' : 'times-circle icon-fail';
            $class = $value ? 'success' : 'error';
            echo "<tr><td>$key</td><td class='test-result $class'><i class='fas fa-$icon'></i> $value</td></tr>";
        }
        echo '</table>';
        echo '</div>';

        /**
         * Test 3: HTML Input Analysis
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-code"></i> TEST 3: HTML Input Configuration</h4>';
        
        foreach (['executive_orders.php', 'resolutions.php'] as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                
                // Check for multiple attribute
                preg_match_all('/name="image_file\[\]"/', $content, $arrayMatches);
                preg_match_all('/multiple/', $content, $multipleMatches);
                preg_match_all('/processFilesWithAutoFill/', $content, $functionMatches);
                
                $arrayCount = count($arrayMatches[0]);
                $multipleCount = count($multipleMatches[0]);
                $functionCount = count($functionMatches[0]);
                
                echo "<div class='test-result info'><strong>$file</strong></div>";
                echo "<table>";
                
                // Array notation check
                if ($arrayCount >= 2) {
                    echo "<tr><td>Array notation (image_file[])</td><td class='test-result success'><i class='fas fa-check-circle icon-pass'></i> Found $arrayCount instances</td></tr>";
                } else {
                    echo "<tr><td>Array notation (image_file[])</td><td class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Only $arrayCount instances (need 2+)</td></tr>";
                }
                
                // Multiple attribute check
                if ($multipleCount >= 2) {
                    echo "<tr><td>'multiple' attribute</td><td class='test-result success'><i class='fas fa-check-circle icon-pass'></i> Found $multipleCount instances</td></tr>";
                } else {
                    echo "<tr><td>'multiple' attribute</td><td class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Only $multipleCount instances (need 2+)</td></tr>";
                }
                
                // JavaScript function check
                if ($functionCount >= 1) {
                    echo "<tr><td>processFilesWithAutoFill()</td><td class='test-result success'><i class='fas fa-check-circle icon-pass'></i> Found $functionCount instances</td></tr>";
                } else {
                    echo "<tr><td>processFilesWithAutoFill()</td><td class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Function not found!</td></tr>";
                }
                
                echo "</table>";
            }
        }
        echo '</div>';

        /**
         * Test 4: PHP Backend Analysis
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-server"></i> TEST 4: PHP Backend Configuration</h4>';
        
        foreach (['executive_orders.php', 'resolutions.php'] as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                
                // Check for proper array handling
                preg_match_all('/\$_FILES\[\'image_file\'\]\[\'name\'\]\[0\]/', $content, $phpArrayMatches);
                preg_match_all('/foreach.*\$_FILES\[\'image_file\'\]\[\'tmp_name\'\]/', $content, $foreachMatches);
                preg_match_all('/implode\(\'[\|]\',/', $content, $pipeMatches);
                
                $arrayHandling = count($phpArrayMatches[0]);
                $foreachCount = count($foreachMatches[0]);
                $pipeCount = count($pipeMatches[0]);
                
                echo "<div class='test-result info'><strong>$file Backend</strong></div>";
                echo "<table>";
                
                if ($arrayHandling >= 2) {
                    echo "<tr><td>Array checking (\$_FILES...[0])</td><td class='test-result success'><i class='fas fa-check-circle icon-pass'></i> Found $arrayHandling checks</td></tr>";
                } else {
                    echo "<tr><td>Array checking (\$_FILES...[0])</td><td class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Only $arrayHandling checks</td></tr>";
                }
                
                if ($foreachCount >= 2) {
                    echo "<tr><td>File loop (foreach)</td><td class='test-result success'><i class='fas fa-check-circle icon-pass'></i> Found $foreachCount loops</td></tr>";
                } else {
                    echo "<tr><td>File loop (foreach)</td><td class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Only $foreachCount loops</td></tr>";
                }
                
                if ($pipeCount >= 2) {
                    echo "<tr><td>Pipe separator (implode)</td><td class='test-result success'><i class='fas fa-check-circle icon-pass'></i> Found $pipeCount uses</td></tr>";
                } else {
                    echo "<tr><td>Pipe separator (implode)</td><td class='test-result warning'><i class='fas fa-exclamation-triangle icon-warn'></i> Only $pipeCount uses</td></tr>";
                }
                
                echo "</table>";
            }
        }
        echo '</div>';

        /**
         * Test 5: Database Table Check
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-database"></i> TEST 5: Database Table Structure</h4>';
        
        if (file_exists('includes/config.php')) {
            require_once 'includes/config.php';
            
            if (isset($conn)) {
                $tables = ['executive_orders', 'resolutions', 'meeting_minutes'];
                
                foreach ($tables as $table) {
                    $result = $conn->query("SHOW COLUMNS FROM $table LIKE 'image_path'");
                    
                    if ($result && $result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        $type = $row['Type'];
                        
                        // Check if TEXT or VARCHAR is large enough for multiple URLs
                        if (strpos($type, 'text') !== false || strpos($type, 'varchar') !== false) {
                            echo "<div class='test-result success'><i class='fas fa-check-circle icon-pass'></i> <strong>$table.image_path</strong> exists (Type: $type)</div>";
                        } else {
                            echo "<div class='test-result warning'><i class='fas fa-exclamation-triangle icon-warn'></i> <strong>$table.image_path</strong> type may be too small: $type</div>";
                        }
                    } else {
                        echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> <strong>$table.image_path</strong> column NOT FOUND!</div>";
                    }
                }
            } else {
                echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Database connection failed!</div>";
            }
        } else {
            echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> Config file not found!</div>";
        }
        echo '</div>';

        /**
         * Test 6: MinIO/S3 Configuration
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-cloud-upload-alt"></i> TEST 6: MinIO/S3 Configuration</h4>';
        
        if (file_exists('includes/minio_helper.php')) {
            require_once 'includes/minio_helper.php';
            
            if (class_exists('MinioS3Client')) {
                echo "<div class='test-result success'><i class='fas fa-check-circle icon-pass'></i> MinioS3Client class exists</div>";
                
                // Try to instantiate
                try {
                    $minioClient = new MinioS3Client();
                    echo "<div class='test-result success'><i class='fas fa-check-circle icon-pass'></i> MinioS3Client instantiated successfully</div>";
                } catch (Exception $e) {
                    echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> MinioS3Client error: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            } else {
                echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> MinioS3Client class not found!</div>";
            }
        } else {
            echo "<div class='test-result warning'><i class='fas fa-exclamation-triangle icon-warn'></i> minio_helper.php not found</div>";
        }
        echo '</div>';

        /**
         * Test 7: Directory Permissions
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-folder-open"></i> TEST 7: Upload Directory Permissions</h4>';
        
        $dirs = [
            'uploads/documents/',
            'uploads/executive_orders/',
            'uploads/resolutions/',
            'uploads/minutes/'
        ];
        
        foreach ($dirs as $dir) {
            if (file_exists($dir)) {
                $perms = substr(sprintf('%o', fileperms($dir)), -4);
                $writable = is_writable($dir);
                
                if ($writable) {
                    echo "<div class='test-result success'><i class='fas fa-check-circle icon-pass'></i> <strong>$dir</strong> exists and writable (Permissions: $perms)</div>";
                } else {
                    echo "<div class='test-result error'><i class='fas fa-times-circle icon-fail'></i> <strong>$dir</strong> exists but NOT writable (Permissions: $perms)</div>";
                }
            } else {
                echo "<div class='test-result warning'><i class='fas fa-exclamation-triangle icon-warn'></i> <strong>$dir</strong> does not exist</div>";
            }
        }
        echo '</div>';

        /**
         * Summary and Recommendations
         */
        echo '<div class="test-section">';
        echo '<h4><i class="fas fa-clipboard-check"></i> SUMMARY & RECOMMENDATIONS</h4>';
        
        echo '<div class="test-result info">';
        echo '<strong>To fix any issues found above:</strong><br><br>';
        echo '1. <strong>Missing Array Notation:</strong> Change <code>name="image_file"</code> to <code>name="image_file[]"</code><br>';
        echo '2. <strong>Missing Multiple Attribute:</strong> Add <code>multiple</code> attribute to file inputs<br>';
        echo '3. <strong>PHP Array Handling:</strong> Use <code>foreach (\$_FILES[\'image_file\'][\'tmp_name\'] as \$key => \$tmpName)</code><br>';
        echo '4. <strong>JavaScript Function:</strong> Ensure <code>processFilesWithAutoFill()</code> is defined<br>';
        echo '5. <strong>Database Column:</strong> Ensure <code>image_path</code> column is TEXT or large VARCHAR<br>';
        echo '6. <strong>Directory Permissions:</strong> Run <code>chmod 755</code> or <code>chmod 777</code> on upload directories<br>';
        echo '</div>';
        
        echo '<div class="test-result warning">';
        echo '<strong>Common Upload Errors:</strong><br><br>';
        echo '• <strong>Only first file uploads:</strong> Missing foreach loop in PHP<br>';
        echo '• <strong>No files upload:</strong> Missing array notation [] or form enctype<br>';
        echo '• <strong>JavaScript errors:</strong> Clear browser cache (Ctrl+Shift+R)<br>';
        echo '• <strong>Database errors:</strong> Check if image_path column can store pipe-separated URLs<br>';
        echo '</div>';
        echo '</div>';

        ?>

        <div style="text-align: center; margin-top: 30px; color: #6a9955;">
            <i class="fas fa-terminal"></i> Diagnostic completed at <?php echo date('Y-m-d H:i:s'); ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
