# ADR 0001: REST per resource, bukan endpoint generik

- Status: diterima
- Tanggal: 2026-11-19
- Fase: 1 (Core)

## Konteks

Mini ERP punya sekitar 20 resource (user, item, purchase order, delivery order, jurnal, dan seterusnya) dengan siklus hidup yang mirip: draft, approve, close. Ada dua bentuk API yang lazim:

1. **Endpoint generik** seperti `POST /documents/{type}/{id}/approve` yang melayani semua jenis dokumen.
2. **REST per resource** seperti `POST /purchase-orders/{id}/approve` dan `POST /delivery-orders/{id}/approve`.

Spesifikasi meminta controller tipis, satu resource satu controller, dan logika per use-case di Action/Service.

## Keputusan

Memakai REST per resource.

- Setiap resource punya controller sendiri (`UserController`, nanti `PurchaseOrderController`, `GoodsReceiptController`).
- Aksi status memakai sub-resource eksplisit: `POST /{resource}/{id}/approve|disapprove|close|print`.
- Aksi lintas resource (misalnya `GET /{resource}/{id}/audit`) tetap per resource, memakai trait bersama `InteractsWithAuditTrail`.
- Logika bisnis tidak berada di controller, melainkan di Action per use-case (`ApprovePurchaseOrder`, `PostGoodsReceipt`).

## Konsekuensi

**Positif**

- Otorisasi granular per resource: policy dan permission `purchase.purchase-order.approve` bisa berbeda dari `purchase.goods-receipt.approve`.
- Dokumen OpenAPI akurat per resource: schema request dan response berbeda nyata (PO punya supplier, DO punya gudang asal dan customer).
- Validasi FormRequest per aksi; tidak ada percabangan `match ($type)` di dalam validasi.
- Frontend memakai URL yang bisa dibaca langsung dari menu, tanpa peta tipe dokumen.

**Negatif**

- Jumlah kelas bertambah: satu controller, satu FormRequest, satu Resource per resource.
- Perilaku bersama (pagination, audit, approve idempoten) harus diekstrak ke trait atau service agar tidak disalin. Sudah dimulai dengan `AppliesListQuery` dan `InteractsWithAuditTrail`.

## Alternatif yang ditolak

- **Endpoint generik.** Menghemat kelas, tetapi memaksa satu controller menangani belasan tipe dokumen dengan percabangan besar, mempersulit policy granular, dan membuat OpenAPI tidak informatif.
- **GraphQL.** Menambah lapisan baru tanpa kebutuhan; laporan dan grid editable lebih mudah dibangun dengan endpoint REST yang jelas.
