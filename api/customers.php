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
require_once __DIR__ . '/../config/auth.php';

require_auth();

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
        json_response(['success' => true, 'customer' => customerForBrowser($customer)]);
        return;
    }

    $search = trim($_GET['search'] ?? '');
    $limit  = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : CUSTOMERS_PAGE_SIZE;
    $offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

    // An unknown folder name would otherwise filter to nothing and read
    // as an empty inbox. '' is the honest fallback: show everything.
    $folder = normalizeCustomerFolder($_GET['folder'] ?? '') ?? '';

    $result = getCustomers($limit, $offset, $search, $folder);

    json_response([
        'success'  => true,
        // customerForBrowser() swaps avatar_path -- a location inside
        // storage/ -- for a URL, and derives the country from the number.
        'customers' => array_map('customerForBrowser', $result['rows']),
        'hasMore'  => $result['hasMore'],
        'nextOffset' => $offset + count($result['rows']),
        // How many sit behind each tab, so the counts stay right after a
        // move without refetching every folder.
        'counts'   => $result['counts'],
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
        'label'      => input_str($data, 'label'),
    ]);

    json_response(['success' => true, 'customer' => customerForBrowser($customer)], 201);
}

function handleUpdate(): void
{
    $data      = read_json_body();
    $sessionId = $_GET['session_id'] ?? input_str($data, 'session_id');

    if ($sessionId === '') {
        json_error('session_id is required', 422);
    }

    // Refused rather than quietly normalised. A label the CRM does not
    // recognise can fall back to "no label" harmlessly, but a folder is a
    // place: silently filing a conversation under Leads because the name
    // did not match is a move the agent did not ask for and would have to
    // notice to undo.
    if (array_key_exists('folder', $data) && normalizeCustomerFolder($data['folder']) === null) {
        json_error('That is not one of the folders.', 422);
    }

    $customer = updateCustomer($sessionId, $data);

    if (!$customer) {
        json_error('Customer not found', 404);
    }

    json_response(['success' => true, 'customer' => customerForBrowser($customer)]);
}
