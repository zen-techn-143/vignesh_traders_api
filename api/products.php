<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true);
$output = array();
$compID = $_GET['id'];
date_default_timezone_set('Asia/Calcutta');

// MySQL query function
function fetchQuery($conn, $sql, $params)
{
    $stmt = $conn->prepare($sql);
    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_all(MYSQLI_ASSOC);
}

// List Products
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];

    $sql = "SELECT product_id, category_id, product_name, hsn_no, item_code, unit_id, subunit_id, unit_rate, opening_stock, 
            DATE_FORMAT(opening_date, '%Y-%m-%d') as opening_date, min_stock, crt_stock 
            FROM product 
            WHERE delete_at = '0' AND company_id = ? AND 
            (product_name LIKE ? OR hsn_no LIKE ? OR item_code LIKE ?)";
    $products = fetchQuery($conn, $sql, [$compID, "%$search_text%", "%$search_text%", "%$search_text%"]);

    if (count($products) > 0) {
        foreach ($products as &$product) {
            // Fetch Unit Details
            if (!empty($product['unit_id'])) {
                $sqlUnit = "SELECT unit_name FROM unit WHERE unit_id = ? AND delete_at = '0' AND company_id = ?";
                $unitResult = fetchQuery($conn, $sqlUnit, [$product['unit_id'], $compID]);
                if (count($unitResult) > 0) {
                    $product['unit_array'][] = ['unit_name' => $unitResult[0]['unit_name'], 'unit_id' => $product['unit_id']];
                }
            }

            // Fetch Subunit Details
            if (!empty($product['subunit_id'])) {
                $sqlSubUnit = "SELECT unit_name FROM unit WHERE unit_id = ? AND delete_at = '0' AND company_id = ?";
                $subUnitResult = fetchQuery($conn, $sqlSubUnit, [$product['subunit_id'], $compID]);
                if (count($subUnitResult) > 0) {
                    $product['unit_array'][] = ['unit_name' => $subUnitResult[0]['unit_name'], 'unit_id' => $product['subunit_id']];
                }
            }

            // Fetch Transactions (Sales/Invoices)
            $sqlInvoice = "SELECT invoice_id, bill_no, DATE_FORMAT(bill_date, '%Y-%m-%d') as bill_date, total, created_date 
                           FROM invoice 
                           WHERE JSON_EXTRACT(product, '$.product_id') = ? AND delete_at = '0' AND company_id = ?";
            $invoices = fetchQuery($conn, $sqlInvoice, [$product['product_id'], $compID]);

            $product['transactions'] = [];
            foreach ($invoices as $invoice) {
                $product['transactions'][] = [
                    'id' => $invoice['invoice_id'],
                    'type' => 'Invoice',
                    'date' => $invoice['bill_date'],
                    'receipt_no' => $invoice['bill_no'],
                    'amount' => $invoice['total'],
                    'create_date' => $invoice['created_date']
                ];
            }

            // Sort transactions by created_date
            usort($product['transactions'], function ($a, $b) {
                return strtotime($b['create_date']) - strtotime($a['create_date']);
            });
        }

        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $products;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Products Found';
    }
}

// Create Product
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['product_name'])) {
    $category_id = $obj['category_id'];
    $product_name = $obj['product_name'];
    $hsn_no = $obj['hsn_no'];
    $item_code = $obj['item_code'];
    $unit_id = $obj['unit_id'];
    $subunit_id = $obj['subunit_id'];
    $unit_rate = $obj['unit_rate'];
    $opening_stock = $obj['opening_stock'];
    $opening_date = $obj['opening_date'];
    $min_stock = $obj['min_stock'];

    $sqlCheck = "SELECT * FROM product WHERE product_name = ? AND delete_at = '0' AND company_id = ?";
    $existingProduct = fetchQuery($conn, $sqlCheck, [$product_name, $compID]);

    if (count($existingProduct) > 0) {
        $output['status'] = 400;
        $output['msg'] = 'Product already exists';
    } else {
        $sqlInsert = "INSERT INTO product (company_id, product_name, category_id, hsn_no, item_code, unit_id, subunit_id, unit_rate, opening_stock, opening_date, min_stock, crt_stock, delete_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, '0')";
        $stmt = $conn->prepare($sqlInsert);
        $stmt->bind_param('ssssssssssss', $compID, $product_name, $category_id, $hsn_no, $item_code, $unit_id, $subunit_id, $unit_rate, $opening_stock, $opening_date, $min_stock, $opening_stock);

        if ($stmt->execute()) {
            $insertId = $conn->insert_id;

            $uniqueID = uniqueID("product", $insertId);
            $sqlUpdate = "UPDATE product SET product_id = ? WHERE id = ? AND company_id = ?";
            $stmtUpdate = $conn->prepare($sqlUpdate);
            $stmtUpdate->bind_param('sis', $uniqueID, $insertId, $compID);

            if ($stmtUpdate->execute()) {
                $output['status'] = 200;
                $output['msg'] = 'Product Created Successfully';
                $output['data'] = ['product_id' => $uniqueID];
            } else {
                $output['status'] = 400;
                $output['msg'] = 'Error updating product';
            }
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error creating product';
        }
    }
}

// Update Product
else if ($_SERVER['REQUEST_METHOD'] == 'UPDATE') {
    $product_id = $obj['product_id'];
    $category_id = $obj['category_id'];
    $product_name = $obj['product_name'];
    $hsn_no = $obj['hsn_no'];
    $item_code = $obj['item_code'];
    $unit_id = $obj['unit_id'];
    $subunit_id = $obj['subunit_id'];
    $unit_rate = $obj['unit_rate'];
    $opening_stock = $obj['opening_stock'];
    $opening_date = $obj['opening_date'];
    $min_stock = $obj['min_stock'];

    if (!empty($product_id)) {
        $sqlUpdate = "UPDATE product SET category_id = ?, product_name = ?, hsn_no = ?, item_code = ?, unit_id = ?, subunit_id = ?, unit_rate = ?, opening_stock = ?, opening_date = ?, min_stock = ? 
                      WHERE product_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sqlUpdate);
        $stmt->bind_param('ssssssssssss', $category_id, $product_name, $hsn_no, $item_code, $unit_id, $subunit_id, $unit_rate, $opening_stock, $opening_date, $min_stock, $product_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Product Updated Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error updating product';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    }
}

// Delete Product
else if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $product_id = $obj['product_id'];

    if (!empty($product_id)) {
        $sqlDelete = "UPDATE product SET delete_at = '1' WHERE product_id = ? AND company_id = ?";
        $stmt = $conn->prepare($sqlDelete);
        $stmt->bind_param('ss', $product_id, $compID);

        if ($stmt->execute()) {
            $output['status'] = 200;
            $output['msg'] = 'Product Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting product';
        }
    } else {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    }
}

echo json_encode($output);
