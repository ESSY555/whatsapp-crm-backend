<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Models\Product;
use Illuminate\Http\Request;

class ProductController extends ApiController
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
            })
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->input('status')))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->successResponse('Products retrieved successfully.', $products);
    }

    public function store(StoreProductRequest $request)
    {
        return $this->successResponse('Product created successfully.', Product::create($request->validated()), 201);
    }

    public function show(int $product)
    {
        return $this->successResponse('Product retrieved successfully.', $this->product($product));
    }

    public function update(UpdateProductRequest $request, int $product)
    {
        $product = $this->product($product);
        $product->update($request->validated());

        return $this->successResponse('Product updated successfully.', $product->fresh());
    }

    public function destroy(int $product)
    {
        $this->product($product)->delete();

        return $this->successResponse('Product deleted successfully.');
    }

    private function product(int $id): Product
    {
        return Product::query()->findOrFail($id);
    }
}
