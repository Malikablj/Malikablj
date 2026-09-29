<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Auth;
use App\Helpers\Database;
use App\Helpers\Request;

/** Pencarian global lintas modul (hanya modul yang boleh dilihat user). */
final class SearchController extends Controller
{
    private const LIMIT = 8;

    public function index(): void
    {
        $q = mb_substr(Request::queryString('q'), 0, 100);
        $sections = [];
        if (mb_strlen($q) >= 2) {
            $like = Database::like($q);
            $p = static fn (int $n): array => array_combine(
                array_map(static fn ($i) => 'q' . $i, range(1, $n)),
                array_fill(0, $n, $like)
            );
            if (Auth::can('customers.view')) {
                $sections['Customers'] = array_map(static fn ($r) => [
                    'url' => '/customers/' . $r['id'], 'title' => $r['name'], 'sub' => $r['code'] . ($r['industry'] ? ' · ' . $r['industry'] : ''), 'badge' => $r['status'], 'icon' => 'bi-buildings',
                ], Database::fetchAll('SELECT id, code, name, industry, status FROM customers WHERE name LIKE :q1 OR company LIKE :q2 OR code LIKE :q3 OR pic LIKE :q4 OR phone LIKE :q5 ORDER BY name LIMIT ' . self::LIMIT, $p(5)));
            }
            if (Auth::can('contacts.view')) {
                $sections['Contacts'] = array_map(static fn ($r) => [
                    'url' => '/customers/' . $r['customer_id'] . '?tab=contacts', 'title' => $r['name'], 'sub' => $r['customer_name'] . ($r['phone'] ? ' · ' . $r['phone'] : ''), 'badge' => null, 'icon' => 'bi-person',
                ], Database::fetchAll('SELECT ct.name, ct.phone, ct.customer_id, c.name AS customer_name FROM contacts ct JOIN customers c ON c.id = ct.customer_id WHERE ct.name LIKE :q1 OR ct.phone LIKE :q2 OR ct.whatsapp LIKE :q3 OR ct.email LIKE :q4 ORDER BY ct.name LIMIT ' . self::LIMIT, $p(4)));
            }
            if (Auth::can('leads.view')) {
                $sections['Leads'] = array_map(static fn ($r) => [
                    'url' => '/leads/' . $r['id'], 'title' => $r['lead_name'], 'sub' => ($r['customer_name'] ?? $r['company_name'] ?? '') . ' · ' . $r['code'], 'badge' => $r['status'], 'icon' => 'bi-kanban',
                ], Database::fetchAll('SELECT l.id, l.code, l.lead_name, l.company_name, l.status, c.name AS customer_name FROM leads l LEFT JOIN customers c ON c.id = l.customer_id WHERE l.lead_name LIKE :q1 OR l.company_name LIKE :q2 OR l.code LIKE :q3 OR l.product_interest LIKE :q4 ORDER BY l.created_at DESC LIMIT ' . self::LIMIT, $p(4)));
            }
            if (Auth::can('purchase_orders.view')) {
                $sections['Purchase Orders'] = array_map(static fn ($r) => [
                    'url' => '/purchase-orders/' . $r['id'], 'title' => $r['po_number'] ?? $r['code'], 'sub' => ($r['customer_name'] ?? 'Customer belum terhubung') . ' · ' . fmt_date($r['po_date']), 'badge' => $r['status'], 'icon' => 'bi-receipt',
                ], Database::fetchAll('SELECT p.id, p.code, p.po_number, p.po_date, p.status, c.name AS customer_name FROM purchase_orders p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.po_number LIKE :q1 OR p.code LIKE :q2 OR c.name LIKE :q3 ORDER BY p.po_date DESC LIMIT ' . self::LIMIT, $p(3)));
            }
            if (Auth::can('deliveries.view')) {
                $sections['Deliveries'] = array_map(static fn ($r) => [
                    'url' => '/deliveries/' . $r['id'], 'title' => $r['sj_number'] ?? $r['code'], 'sub' => ($r['destination'] ?? '') . ' · ' . fmt_date($r['delivery_date']), 'badge' => $r['status'], 'icon' => 'bi-truck',
                ], Database::fetchAll('SELECT id, code, sj_number, destination, delivery_date, status FROM deliveries WHERE sj_number LIKE :q1 OR code LIKE :q2 ORDER BY delivery_date DESC LIMIT ' . self::LIMIT, $p(2)));
            }
            if (Auth::can('products.view')) {
                $sections['Products'] = array_map(static fn ($r) => [
                    'url' => '/products/' . $r['id'], 'title' => $r['name'], 'sub' => trim(($r['product_code'] ?? '') . ' ' . ($r['variant'] ?? '')) ?: $r['code'], 'badge' => null, 'icon' => 'bi-box-seam',
                ], Database::fetchAll('SELECT id, code, name, product_code, variant FROM products WHERE name LIKE :q1 OR code LIKE :q2 OR product_code LIKE :q3 OR variant LIKE :q4 ORDER BY name LIMIT ' . self::LIMIT, $p(4)));
            }
            if (Auth::can('finance.view')) {
                $sections['Invoices'] = array_map(static fn ($r) => [
                    'url' => '/invoices/' . $r['id'], 'title' => $r['invoice_number'] ?? $r['code'], 'sub' => fmt_money($r['invoice_amount']) . ' · ' . fmt_date($r['invoice_date']), 'badge' => $r['status'], 'icon' => 'bi-cash-coin',
                ], Database::fetchAll('SELECT id, code, invoice_number, invoice_amount, invoice_date, status FROM invoices_payments WHERE invoice_number LIKE :q1 OR code LIKE :q2 OR po_number_legacy LIKE :q3 ORDER BY invoice_date DESC LIMIT ' . self::LIMIT, $p(3)));
            }
            $sections = array_filter($sections);
        }
        $this->view('search/index', ['title' => 'Pencarian', 'q' => $q, 'sections' => $sections]);
    }
}
