<?php
// Debug script to check file upload structure
session_start();

echo "<h2>POST Debug</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

echo "<h2>FILES Debug</h2>";
echo "<pre>";
print_r($_FILES);
echo "</pre>";

if (isset($_FILES['image_file'])) {
    echo "<h2>File Check</h2>";
    echo "isset: " . (isset($_FILES['image_file']) ? 'YES' : 'NO') . "<br>";
    echo "is_array tmp_name: " . (is_array($_FILES['image_file']['tmp_name']) ? 'YES' : 'NO') . "<br>";
    echo "empty name[0]: " . (empty($_FILES['image_file']['name'][0]) ? 'YES' : 'NO') . "<br>";
    
    if (is_array($_FILES['image_file']['tmp_name'])) {
        echo "<br><strong>Array structure detected</strong><br>";
        echo "Count: " . count($_FILES['image_file']['tmp_name']) . "<br>";
        foreach ($_FILES['image_file']['tmp_name'] as $key => $tmpName) {
            echo "<br>File $key:<br>";
            echo "  - name: " . $_FILES['image_file']['name'][$key] . "<br>";
            echo "  - tmp: " . $tmpName . "<br>";
            echo "  - error: " . $_FILES['image_file']['error'][$key] . "<br>";
            echo "  - empty check: " . (empty($_FILES['image_file']['name'][$key]) ? 'EMPTY' : 'NOT EMPTY') . "<br>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>File Upload Debug</title>
</head>
<body>
    <h1>Test Multiple File Upload</h1>
    <form method="POST" enctype="multipart/form-data">
        <label>Select Files:</label><br>
        <input type="file" name="image_file[]" multiple accept=".jpg,.jpeg,.png,.pdf"><br><br>
        <button type="submit">Upload</button>
    </form>
</body>
</html>
