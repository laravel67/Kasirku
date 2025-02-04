<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\Cart;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Unit;
use CodeIgniter\HTTP\ResponseInterface;

class SaleController extends BaseController
{
    protected $product;
    protected $category;
    protected $unit;
    protected $cart;
    protected $sale;

    public function __construct()
    {
        $this->product = new Product();
        $this->category = new Product();
        $this->cart = new Cart();
        $this->unit = new Unit();
        $this->sale = new Sale();
    }

    public function index()
    {
        return view('pages/sale/index');
    }


    public function product()
    {
        $categories = $this->category->get()->getResult();
        $units = $this->unit->get()->getResult();
        return view('pages/sale/products', compact('categories', 'units'));
    }

    public function getProductsInSale()
    {
        $param['draw'] = isset($_REQUEST['draw']) ? (int) $_REQUEST['draw'] : 0;
        $search = isset($_REQUEST['search']['value']) ? $_REQUEST['search']['value'] : '';
        $start = isset($_REQUEST['start']) ? (int) $_REQUEST['start'] : 0;
        $length = isset($_REQUEST['length']) ? (int) $_REQUEST['length'] : 12;
        $category = isset($_REQUEST['category_id']) ? $_REQUEST['category_id'] : null;
        $unit = isset($_REQUEST['unit_id']) ? $_REQUEST['unit_id'] : null;
        $data = $this->product->filterProducts($search, $start, $length, $category, $unit);
        foreach ($data as &$row) {
            $category_id = isset($row->category_id) ? esc($row->category_id) : 'N/A';
            $unit_id = isset($row->unit_id) ? esc($row->unit_id) : 'N/A';

            $row->view = '
                    <a class="col-xl-2 col-lg-3 col-md-3 col-sm-3 product-card my-2"
                        data-category="' . $category_id . '"
                        data-unit="' . $unit_id . '"
                        data-name="' . strtolower(esc($row->name)) . '">
                        <div class="card shadow">
                            <img class="img-fluid" width="100%" height="50%" src="' . (esc($row->image) ?? 'https://placehold.co/50x50') . '" alt="' . esc($row->name) . '">
                            <div class="card-body">
                                <h6 class="card-title mb-0">' . esc($row->name) . '</h6>
                                <strong class="m-0">' . esc(rp($row->sell_price)) . '</strong>
                                <small class="m-0 text-muted">/ Stok ' . esc($row->stock) . '</small>
                                <div class="d-flex justify-content-between">
                                    <button class="btn btn-light btn-sm">
                                        <i class="fa-solid fa-folder-open"></i>
                                    </button>
                                    <form action="' . route_to('add_cart', esc($row->id)) . '" method="POST">
                                        ' . csrf_field() . '
                                        <div class="input-group">
                                            <input type="hidden" name="id" value="' . esc($row->id) . '">
                                            <input type="number" name="quantity" value="1" class="form-control text-center form-control-sm rounded-1">
                                            <button type="submit" class="btn btn-sm btn-success rounded-1">
                                                <i class="fa-solid fa-shopping-cart"></i>
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </a>
                    ';
        }
        $recordsTotal = $this->product->countAllResults();
        $recordsFiltered = $this->product->filteredCount; // Pastikan Anda menghitung jumlah hasil yang difilter

        $output = array(
            "draw" => $param['draw'],
            "recordsTotal" => $recordsTotal,
            "recordsFiltered" => $recordsFiltered,
            "data" => $data
        );

        return $this->response->setJSON($output);
    }


    public function addTocart(int $id)
    {
        $quantity = (int) $this->request->getPost('quantity');
        if (empty($id) || $quantity <= 0) {
            session()->setFlashdata('error', 'Tentukan kuantitas produk yang ingin dibeli');
            return redirect()->to(route_to('product_sale'));
        }
        $product = $this->product->find($id);
        if (!$product) {
            session()->setFlashdata('error', 'Produk tidak ditemukan.');
            return redirect()->to(route_to('product_sale'));
        }
        if ($product['stock'] < $quantity) {
            session()->setFlashdata('error', 'Stok tidak mencukupi.');
            return redirect()->to(route_to('product_sale'));
        }
        $subTotal = $product['sell_price'] * $quantity;
        $cart = $this->cart->where('product_id', $id)->first();
        if ($cart) {
            $newQuantity = $cart['quantity'] + $quantity;
            $newSubTotal = $product['sell_price'] * $newQuantity;
            $cartData = [
                'quantity'  => $newQuantity,
                'subtotal'  => $newSubTotal
            ];
            if ($this->cart->update($cart['id'], $cartData)) {
                session()->setFlashdata('success', 'Keranjang berhasil diperbarui!');
            } else {
                session()->setFlashdata('error', 'Gagal memperbarui keranjang.');
            }
        } else {
            $cartData = [
                'product_id' => $id,
                'quantity'  => $quantity,
                'subtotal'  => $subTotal
            ];
            if ($this->cart->save($cartData)) {
                session()->setFlashdata('success', 'Produk berhasil ditambah ke keranjang!');
            } else {
                session()->setFlashdata('error', 'Gagal menambahkan produk ke keranjang.');
            }
        }
        return redirect()->to(route_to('product_sale'));
    }

    public function carts()
    {
        $products = $this->cart
            ->select('carts.*, products.name as product_name, products.code as product_code, products.sell_price as product_price')
            ->join('products', 'products.id = carts.product_id', 'left')
            ->get()
            ->getResult();
        $total = array_sum(array_map(function ($product) {
            return $product->product_price * $product->quantity;
        }, $products));
        $currentDate = date('Ymd');
        $lastInvoice = $this->sale
            ->select('invoice')
            ->like('invoice', $currentDate)
            ->orderBy('invoice', 'desc')
            ->limit(1)
            ->get()
            ->getRow();
        if ($lastInvoice) {
            $invoiceParts = explode('-', $lastInvoice->invoice);
            $lastInvoiceNumber = (int) $invoiceParts[2];
        } else {
            $lastInvoiceNumber = 0;
        }

        $invoiceNumber = str_pad($lastInvoiceNumber + 1, 3, '0', STR_PAD_LEFT);
        $invoice = 'INV-' . $currentDate . '-' . $invoiceNumber;
        $data = [
            'title' => 'Daftar Belanja',
            'carts' => $products,
            'total' => $total,
            'invoice' => $invoice,
        ];

        return view('pages/sale/cart', $data);
    }


    public function updateToCart(int $id)
    {
        $quantity = $this->request->getPost('quantity');
        if (!is_numeric($quantity) || $quantity <= 0) {
            return redirect()->back()->with('error', 'Jumlah harus lebih dari 0.');
        }
        $cart = $this->cart->find($id);
        if (!$cart) {
            return redirect()->back()->with('error', 'Keranjang tidak ditemukan.');
        }
        $product = $this->product->find($cart['product_id']);
        if (!$product) {
            return redirect()->back()->with('error', 'Produk tidak ditemukan.');
        }
        $productPrice = $product['sell_price'];
        $subtotal = $productPrice * $quantity;
        $cartUpdate = [
            'id'       => $id,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
        if ($this->cart->save($cartUpdate)) {
            return redirect()->to('/carts')->with('success', 'Daftar belanja berhasil diperbarui!');
        } else {
            return redirect()->to('/carts')->with('error', 'Daftar belanja gagal diperbarui!');
        }
    }

    public function deleteCart(int $id)
    {
        $cart = $this->cart->where('id', $id)->first();
        if (!$cart) {
            return redirect()->back()->with('error', 'Produk tidak ditemukan');
        }
        if ($this->cart->delete($cart['id'])) {
            return redirect()->to(route_to('carts'))->with('success', 'Produk berhasil dihapus dari daftar belanja !');
        } else {
            return redirect()->back()->with('error', 'Produk gagal dihapus!');
        }
    }

    public function invoice()
    {
        return view('pages/sale/invoice');
    }
}
