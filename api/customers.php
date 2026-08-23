<?php
/**
 * api/customers.php
 *
 * REST-ish endpoint for the customer directory.
 *
 *   GET  /api/customers.php?search=&offset=&limit=   -> paginated list
 *   GET  /api/customers.php?session_id=xxx            -> single customer
 *   POST /api/customers.php                           -> create customer
 *   PUT  /api/customers.php  (?session_id=xxx)         -> update customer
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_functions.php';
require_once __DIR__ . '/../config/app.php';

$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            handleGet();
            break;
        case 'POST':
            handleCreate();
            break;
        case 'PUT':
            handleUpdate();
            break;
        default:
            json_error('Method not allowed', 405);
    }
} catch (SupabaseException $e) {
    error_log('[api/customers] ' . $e->getMessage());
    json_error($e->getMessage(), $e->httpStatus);
} catch (Throwable $e) {
    error_log('[api/customers] ' . $e->getMessage());
    json_error('Something went wrong while talking to the database.', 500);
}

function handleGet(): void
{
    $sessionId = $_GET['session_id'] ?? '';

    if ($sessionId !== '') {
        $customer = getCustomer($sessionId);
        if (!$customer) {
            json_error('Customer not found', 404);
        }
        json_response(['success' => true, 'customer' => $customer]);
        return;
    }

    $search = trim($_GET['search'] ?? '');
    $limit  = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : CUSTOMERS_PAGE_SIZE;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    $result = getCustomers($limit, $offset, $search);

    json_response([
        'success'  => true,
        'customers' => $result['rows'],
        'hasMore'  => $result['hasMore'],
        'nextOffset' => $offset + count($result['rows']),
    ]);
}

function handleCreate(): void
{
    $data = read_json_body();

    $customer = createCustomer([
        'first_name' => input_str($data, 'first_name'),
        'last_name'  => input_str($data, 'last_name'),
        'username'   => input_str($data, 'username'),
        'phone'      => input_str($data, 'phone'),
        'country'    => input_str($data, 'country'),
        'email'      => input_str($data, 'email'),
        'city'       => input_str($data, 'city'),
        'address'    => input_str($data, 'address'),
        'tax_id'     => input_str($data, 'tax_id'),
        'details'    => input_str($data, 'details'),
    ]);

    json_response(['success' => true, 'customer' => $customer], 201);
}

function handleUpdate(): void
{
    $data      = read_json_body();
    $sessionId = $_GET['session_id'] ?? input_str($data, 'session_id');

    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    $customer = updateCustomer($sessionId, $data);

    if (!$customer) {
        json_error('Customer not found', 404);
    }

    json_response(['success' => true, 'customer' => $customer]);
}
