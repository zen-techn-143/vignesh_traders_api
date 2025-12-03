<?php
include 'config/dbconfig.php';
header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: http://localhost:3000");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

$json = file_get_contents('php://input');
$obj = json_decode($json, true);
$output = array();
$compID = $_GET['id'];
date_default_timezone_set('Asia/Calcutta');

function fetchQuery($conn, $sql, $params)
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: (" . $conn->errno . ") " . $conn->error);
    }

    if ($params) {
        $stmt->bind_param(str_repeat("s", count($params)), ...$params);
    }

    if (!$stmt->execute()) {
        die("Execute failed: (" . $stmt->errno . ") " . $stmt->error);
    }

    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}



// List Sale Invoices
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['search_text'])) {
    $search_text = $obj['search_text'];
    $party_id = $obj['party_id'];
    $from_Date = $obj['from_date'];
    $to_date = $obj['to_date'];

    // SQL Query
    $sql = "SELECT party_id, party_details, bill_no, bill_date, product, total, paid, company_details, 
            eway_no, vechile_no, billing_address, shipp_address, mobile_number, sum_total, state_of_supply 
            FROM invoice 
            WHERE delete_at = '0' 
            AND company_id = ? 
            AND (party_id = ? 
            OR (bill_date BETWEEN ? AND ?) 
            OR JSON_EXTRACT(party_details, '$.party_name') LIKE ? 
            OR bill_no LIKE ?)";

    // Prepare parameters
    $params = [$compID, $party_id, $from_Date, $to_date, "%$search_text%", "%$search_text%"];
    $invoices = fetchQuery($conn, $sql, $params);

    if (
        count($invoices) > 0
    ) {
        foreach ($invoices as &$invoice) {
            $invoice['bill_date'] = date('Y-m-d', strtotime($invoice['bill_date']));
            $invoice['party_details'] = json_decode($invoice['party_details'], true);
            $invoice['product'] = json_decode($invoice['product'], true);
            $invoice['company_details'] = json_decode($invoice['company_details'], true);
        }

        $output['status'] = 200;
        $output['msg'] = 'Success';
        $output['data'] = $invoices;
    } else {
        $output['status'] = 400;
        $output['msg'] = 'No Records Found';
    }
}


// Create Sale Invoice
// Create Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($obj['party_id'])) {
    $party_id = $obj['party_id'];
    $bill_date = $obj['bill_date'];
    $eway_no = $obj['eway_no'];
    $vechile_no = $obj['vechile_no'];
    $billing_address = $obj['billing_address'];
    $shipp_address = $obj['shipp_address'];
    $product = $obj['product'];
    $total = $obj['total'];
    $paid = $obj['paid'];
    $balance_amount = $obj['balance_amount'];
    $mobile_number = $obj['mobile_number'];
    $state_of_supply = $obj['state_of_supply'];

    try {
        // Log the incoming request data for debugging
        file_put_contents("debug_log.txt", "Received data: " . print_r($obj, true), FILE_APPEND);

        // Parameter validation
        if (!$party_id || !$bill_date || !$product || !isset($total)) {
            file_put_contents("debug_log.txt", "Parameter mismatch: party_id: $party_id, bill_date: $bill_date, product: " . print_r($product, true) . ", total: $total", FILE_APPEND);
            return json_encode([
                'status' => 400,
                'msg' => "Parameter Mismatch",
            ]);
        }

        // Fetch party details
        $sqlparty = "SELECT * FROM `sales_party` WHERE `party_id` = ? AND `company_id` = ?";
        $partyResult = fetchQuery($conn, $sqlparty, [$party_id, $compID]);

        if (empty($partyResult)) {
            file_put_contents("debug_log.txt", "Party details not found for party_id: $party_id", FILE_APPEND);
            return json_encode(['status' => 400, 'msg' => 'Party Details Not Found']);
        }

        $sum_total = 0;

        // Process each product
        foreach ($product as &$element) {
            $sqlProduct = "SELECT * FROM product WHERE product_id = ? AND company_id = ?";
            $productData = fetchQuery($conn, $sqlProduct, [$element['product_id'], $compID]);

            if (empty($productData)) {
                file_put_contents("debug_log.txt", "Product not found: product_id: " . $element['product_id'], FILE_APPEND);
                return json_encode(['status' => 400, 'msg' => 'Product Details Not Found']);
            }

            // Calculate without tax amount
            $element['without_tax_amount'] = (floatval($element['qty']) * floatval($element['price_unit'])) - (empty($element['discount_amt']) ? 0 : floatval($element['discount_amt']));
            $sum_total += $element['without_tax_amount'];

            $element['hsn_no'] = $productData[0]['hsn_no'];
            $element['item_code'] = $productData[0]['item_code'];
            $element['product_name'] = $productData[0]['product_name'];
            $element['db_unit_id'] = $productData[0]['unit_id'];
            $element['db_subunit_id'] = $productData[0]['subunit_id'];

            // Fetch unit name
            $sqlunit = "SELECT unit_name FROM unit WHERE unit_id = ? AND delete_at = 0";
            $unitData = fetchQuery($conn, $sqlunit, [$element['unit']]);
            if (!empty($unitData)) {
                $element['unit_name'] = $unitData[0]['unit_name'];
            }
        }

        // Get company details
        $companyDetailsSQL = "SELECT * FROM company WHERE company_id = ?";
        $companyDetailsresult = fetchQuery($conn, $companyDetailsSQL, [$compID]);

        if (empty($companyDetailsresult)) {
            file_put_contents("debug_log.txt", "Company details not found for company_id: $compID", FILE_APPEND);
            return json_encode(['status' => 400, 'msg' => 'Company Details Not Found']);
        }

        $companyData = json_encode($companyDetailsresult[0]);
        $party_details = json_encode($partyResult[0]);
        $product_json = json_encode($product);
        $billDate = date('Y-m-d', strtotime($bill_date));

        // Log the invoice data
        file_put_contents("debug_log.txt", "Preparing to insert invoice with data: product_json: $product_json, sum_total: $sum_total", FILE_APPEND);

        // Insert invoice into database
        $sqlinvoice = "INSERT INTO invoice (company_id, party_id, party_details, bill_date, product, total, paid, balance, delete_at, eway_no, vechile_no, billing_address, mobile_number, company_details, sum_total, state_of_supply) 
               VALUES (
                   '$compID', 
                   '$party_id', 
                   '$party_details', 
                   '$billDate', 
                   '$product_json', 
                   '$total', 
                   '" . ($paid ?: '0') . "', 
                   '" . strval($balance_amount) . "', 
                   '0', 
                   '$eway_no', 
                   '$vechile_no', 
                   '$billing_address', 
                   '" . strval($mobile_number) . "', 
                   '$companyData', 
                   '" . strval($sum_total) . "', 
                   '$state_of_supply'
               )";

        // Execute the query and log the result
        if ($conn->query($sqlinvoice) === TRUE) {
            $id = $conn->insert_id;
            file_put_contents("debug_log.txt", "Invoice created successfully. Invoice ID: $id", FILE_APPEND);
            echo json_encode(['status' => 200, 'msg' => 'Invoice created successfully', 'id' => $id]);
        } else {
            file_put_contents("debug_log.txt", "Invoice creation failed: " . $conn->error, FILE_APPEND);
            echo json_encode(['status' => 400, 'msg' => 'Invoice Creation Failed: ' . $conn->error]);
        }

        // Generate unique invoice ID
        $uniqueID = uniqueID("invoice", $id);
        file_put_contents("debug_log.txt", "Generated unique invoice ID: $uniqueID", FILE_APPEND);

        // Fetch the last bill number from the database
        $lastBillSql = "SELECT bill_no FROM invoice WHERE company_id = ? AND bill_no IS NOT NULL ORDER BY id DESC LIMIT 1";
        $resultLastBill = fetchQuery($conn, $lastBillSql, [$compID]);

        // Fetch bill prefix from the company settings
        $billPrefixSql = "SELECT bill_prefix FROM company WHERE company_id = ?";
        $resultBillPrefix = fetchQuery($conn, $billPrefixSql, [$compID]);

        // Determine the fiscal year
        $year = date('y');
        $fiscal_year = ($year . '-' . ($year + 1));

        // Initialize the bill number
        $billcount = 1;

        if (!empty($resultLastBill[0]['bill_no'])) {
            preg_match('/\/(\d+)\/\d{2}-\d{2}$/', $resultLastBill[0]['bill_no'], $matches);
            if (isset($matches[1])) {
                $billcount = (int) $matches[1] + 1;
            }
        }

        $billcountFormatted = str_pad($billcount, 3, '0', STR_PAD_LEFT);
        $bill_no = $resultBillPrefix[0]['bill_prefix'] . '/' . $billcountFormatted . '/' . $fiscal_year;

        file_put_contents("debug_log.txt", "Generated bill number: $bill_no", FILE_APPEND);

        // Update the invoice with generated ID and new bill number
        $sqlUpdate = "UPDATE invoice SET invoice_id = ?, bill_no = ? WHERE id = ? AND company_id = ?";
        $updateResult = fetchQuery($conn, $sqlUpdate, [$uniqueID, $bill_no, $id, $compID]);

        if (empty($updateResult)) {
            file_put_contents("debug_log.txt", "Invoice update failed", FILE_APPEND);
            return json_encode(['status' => 400, 'msg' => 'Invoice Update Failed']);
        }

        // Update product stock and log stock history
        foreach ($product as $element) {
            $productId = $element['product_id'];
            $quantity = (int) $element['qty'];

            $getStockSql = "SELECT crt_stock FROM product WHERE product_id = ? AND company_id = ?";
            $getStock = fetchQuery($conn, $getStockSql, [$productId, $compID]);

            if (empty($getStock)) {
                file_put_contents("debug_log.txt", "Failed to retrieve stock for product_id: $productId", FILE_APPEND);
                return json_encode(['status' => 400, 'msg' => 'Failed to retrieve stock']);
            }

            $quantity_purchased = $getStock[0]['crt_stock'] - $quantity;

            $updateStockSql = "UPDATE product SET crt_stock = ? WHERE product_id = ? AND company_id = ?";
            $updateResult = fetchQuery($conn, $updateStockSql, [$quantity_purchased, $productId, $compID]);

            if (empty($updateResult)) {
                return json_encode(['status' => 400, 'msg' => 'Stock Update Failed']);
            }

            // Log stock history
            $stockSql = "INSERT INTO stock_history (stock_type, bill_no, product_id, product_name, quantity, company_id, bill_id) 
                          VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stockResult = fetchQuery($conn, $stockSql, ['STACKOUT', $bill_no, $productId, $element['product_name'], $quantity, $compID, $uniqueID]);

            if (empty($stockResult)) {
                return json_encode(['status' => 400, 'msg' => 'Stock History Insertion Failed']);
            }
        }

        return json_encode([
            'status' => 200,
            'msg' => 'Invoice Created Successfully',
            'data' => ['invoice_id' => $uniqueID],
        ]);
    } catch (Exception $error) {
        return json_encode([
            'status' => 400,
            'msg' => $error->getMessage(),
        ]);
    }
} // Update Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'PUT') {
    $json = file_get_contents('php://input');
    $obj = json_decode($json, true);

    $invoice_id = $obj['invoice_id'];
    $party_id = $obj['party_id'];
    $party_details = $obj['party_details'];
    $company_details = $obj['company_details'];
    $bill_no = $obj['bill_no'];
    $bill_date = $obj['bill_date'];
    $product = $obj['product'];
    $eway_no = $obj['eway_no'];
    $vechile_no = $obj['vechile_no'];
    $billing_address = $obj['billing_address'];
    $shipp_address = $obj['shipp_address'];
    $mobile_number = $obj['mobile_number'];
    $total = $obj['total'];
    $sum_total = $obj['sum_total'];
    $round_off = $obj['round_off'];
    $paid = $obj['paid'];
    $balance = $obj['balance'];
    $state_of_supply = $obj['state_of_supply'];

    if (!$invoice_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
        echo json_encode($output);
        return;
    }

    // Fetch the old invoice details
    $sqlInvoice = "SELECT * FROM invoice WHERE invoice_id = ? AND company_id = ?";
    $resultInvoice = fetchQuery($conn, $sqlInvoice, [$invoice_id, $compID]);

    if ($resultInvoice['status'] !== 200 || count($resultInvoice['data']) === 0) {
        $output['status'] = 400;
        $output['msg'] = 'Invoice Not Found';
        echo json_encode($output);
        return;
    }

    $oldProductList = json_decode($resultInvoice['data'][0]['product'], true);

    // Revert the stock changes made by the old invoice
    foreach ($oldProductList as $oldProduct) {
        $oldQty = (int) $oldProduct['qty'];
        $sqlOldProduct = "SELECT crt_stock FROM product WHERE product_id = ? AND company_id = ?";
        $oldProductData = fetchQuery($conn, $sqlOldProduct, [$oldProduct['product_id'], $compID]);

        if ($oldProductData['status'] === 200 && count($oldProductData['data']) > 0) {
            $newQty = $oldProductData['data'][0]['crt_stock'] + $oldQty;
            $updateStockSql = "UPDATE product SET crt_stock = ? WHERE product_id = ? AND company_id = ?";
            fetchQuery($conn, $updateStockSql, [$newQty, $oldProduct['product_id'], $compID]);
        }
    }

    // Update the invoice details
    $product_json = json_encode($product);
    $billDate = date('Y-m-d', strtotime($bill_date));

    $sqlUpdateInvoice = "UPDATE invoice SET party_id = ?, party_details = ?, company_details = ?, bill_no = ?, 
                         bill_date = ?, product = ?, eway_no = ?, vechile_no = ?, billing_address = ?, 
                         shipp_address = ?, mobile_number = ?, total = ?, sum_total = ?, round_off = ?, 
                         paid = ?, balance = ?, state_of_supply = ? 
                         WHERE invoice_id = ? AND company_id = ?";

    $paramsUpdate = [
        $party_id,
        json_encode($party_details),
        json_encode($company_details),
        $bill_no,
        $billDate,
        $product_json,
        $eway_no,
        $vechile_no,
        $billing_address,
        $shipp_address,
        $mobile_number,
        $total,
        $sum_total,
        $round_off,
        $paid,
        $balance,
        $state_of_supply,
        $invoice_id,
        $compID
    ];

    $resultUpdateInvoice = fetchQuery($conn, $sqlUpdateInvoice, $paramsUpdate);

    if ($resultUpdateInvoice['status'] !== 200) {
        $output['status'] = 400;
        $output['msg'] = 'Invoice Update Failed';
        echo json_encode($output);
        return;
    }

    // Update stock based on the new invoice details
    foreach ($product as $newProduct) {
        $productId = $newProduct['product_id'];
        $quantity = (int) $newProduct['qty'];
        $productName = $newProduct['product_name'];

        $getStockSql = "SELECT crt_stock FROM product WHERE product_id = ? AND company_id = ?";
        $getStock = fetchQuery($conn, $getStockSql, [$productId, $compID]);

        if ($getStock['status'] === 200 && count($getStock['data']) > 0) {
            $quantityPurchased = $getStock['data'][0]['crt_stock'] - $quantity;

            // Update product stock
            $updateStockSql = "UPDATE product SET crt_stock = ? WHERE product_id = ? AND company_id = ?";
            fetchQuery($conn, $updateStockSql, [$quantityPurchased, $productId, $compID]);

            // Log stock history
            $stockSql = "INSERT INTO stock_history (stock_type, bill_no, product_id, product_name, quantity, company_id, bill_id) VALUES (?, ?, ?, ?, ?, ?, ?)";
            fetchQuery($conn, $stockSql, ['STACKOUT', $bill_no, $productId, $productName, $quantity, $compID, $invoice_id]);
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Failed to retrieve stock';
            echo json_encode($output);
            return;
        }
    }

    $output['status'] = 200;
    $output['msg'] = 'Invoice Updated Successfully';
    $output['data'] = ['invoice_id' => $invoice_id];
    echo json_encode($output);
}


// Delete Sale Invoice
else if ($_SERVER['REQUEST_METHOD'] == 'DELETE') {
    $invoice_id = $obj['invoice_id'];

    if (!$invoice_id) {
        $output['status'] = 400;
        $output['msg'] = 'Parameter Mismatch';
    } else {
        $sqlDelete = "UPDATE invoice SET delete_at = '1' WHERE invoice_id = ? AND company_id = ?";
        $paramsDelete = [$invoice_id, $compID];

        if (fetchQuery($conn, $sqlDelete, $paramsDelete)) {
            $output['status'] = 200;
            $output['msg'] = 'Invoice Deleted Successfully';
        } else {
            $output['status'] = 400;
            $output['msg'] = 'Error deleting invoice';
        }
    }
} else {
    $output['status'] = 400;
    $output['msg'] = 'Invalid Request Method';
}

echo json_encode($output);
