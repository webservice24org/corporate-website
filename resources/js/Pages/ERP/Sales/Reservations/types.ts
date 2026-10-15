export type Order = { id: number; number: string; status: string; revision: number; confirmed_at: string | null; customer_name: string };
export type Item = { id: number; product_id: number; variant_key: number; name: string; variant_name: string | null; unit: string; quantity: string; remaining: string; reserved: string; unreserved: string; requires_stock: boolean };
export type Reservation = { id: number; sales_order_item_id: number; warehouse_id: number; allocated: string; released: string; consumed: string; active: string };
export type Warehouse = { id: number; name: string; code: string; is_active: boolean; deleted_at: string | null };
export type Balance = { warehouse_id: number; product_id: number; variant_key: number; on_hand: string; reserved: string; available: string };
export type Event = { id: number; sales_stock_reservation_id: number; action: string; quantity: string; reason: string | null; actor_name: string; occurred_at: string };
