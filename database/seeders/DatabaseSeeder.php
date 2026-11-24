<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::updateOrCreate(['email' => 'admin@alquiler.test'], ['name' => 'Administrador demo', 'dni' => '70000001', 'phone' => '999 111 111', 'role' => 'admin', 'active' => true, 'password' => Hash::make('Admin123!')]);
        $tenants = [];
        foreach ([['Ana Torres', '70000002', 'ana@alquiler.test', '988 200 301'], ['Luis Ramírez', '70000003', 'luis@alquiler.test', '988 200 302'], ['María Flores', '70000004', 'maria@alquiler.test', '988 200 303']] as [$name,$dni,$email,$phone]) {
            $tenants[] = User::updateOrCreate(['email' => $email], ['name' => $name, 'dni' => $dni, 'phone' => $phone, 'role' => 'tenant', 'active' => true, 'password' => Hash::make('Inquilino123!')]);
        }
        $rooms = [];
        foreach ([['A-101', 'Habitación con baño privado', 850, 'occupied'], ['A-102', 'Habitación amoblada', 720, 'occupied'], ['B-201', 'Suite con kitchenette', 1100, 'occupied'], ['B-202', 'Habitación iluminada', 680, 'available'], ['C-301', 'Estudio independiente', 1300, 'maintenance']] as [$number,$description,$rent,$status]) {
            $rooms[] = Room::updateOrCreate(['number' => $number], compact('description', 'rent', 'status'));
        }
        foreach (array_slice($tenants, 0, 3) as $i => $tenant) {
            $contract = Contract::updateOrCreate(['user_id' => $tenant->id, 'room_id' => $rooms[$i]->id, 'status' => 'active'], ['starts_at' => now()->subMonths(5), 'ends_at' => now()->addMonths(7), 'rent' => $rooms[$i]->rent, 'deposit' => $rooms[$i]->rent, 'due_day' => 5]);
            foreach ([now()->subMonth(), now()] as $date) {
                $period = $date->format('Y-m');
                $due = $date->copy()->day(5);
                Invoice::updateOrCreate(['contract_id' => $contract->id, 'type' => 'rent', 'period' => $period], ['amount' => $contract->rent, 'due_at' => $due, 'status' => 'pending', 'paid_at' => null]);
            }
        }
    }
}
