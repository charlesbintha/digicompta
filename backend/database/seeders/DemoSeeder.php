<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\InvoiceWorkflow;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Les données de démonstration sont réservées à un environnement local.');
        }
        if (User::where('email', 'demo@digicompta.test')->exists()) {
            return;
        }
        DB::transaction(function () {
            $user = User::create(['name' => 'Aminata Ndiaye', 'email' => 'demo@digicompta.test', 'password' => 'Demo-Senegal-2026!']);
            $company = DB::table('companies')->insertGetId(['name' => 'Atelier Teranga', 'address' => 'Mermoz, Dakar, Sénégal', 'plan' => 'Business', 'subscription_status' => 'trial', 'trial_ends_at' => now()->addDays(14), 'invoice_sequence' => 6, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('memberships')->insert(['company_id' => $company, 'user_id' => $user->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
            $clients = [];
            foreach (['Maison Baobab', 'Studio Ndar', 'Les Jardins de Saly', 'Teranga Distribution', 'Keur Design'] as $index => $name) {
                $clients[] = DB::table('contacts')->insertGetId(['company_id' => $company, 'type' => 'client', 'name' => $name, 'email' => 'contact'.($index + 1).'@example.test', 'phone' => '+221 77 000 00 0'.$index, 'address' => 'Dakar, Sénégal', 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('contacts')->insert(['company_id' => $company, 'type' => 'supplier', 'name' => 'Papeterie du Plateau', 'email' => 'papeterie@example.test', 'address' => 'Plateau, Dakar', 'created_at' => now(), 'updated_at' => now()]);
            foreach ([['Identité visuelle', 350000, 'service'], ['Accompagnement mensuel', 175000, 'service'], ['Pack de communication', 250000, 'service'], ['Carnet personnalisé', 5000, 'product']] as $index => $product) {
                DB::table('products')->insert(['company_id' => $company, 'name' => $product[0], 'sku' => 'ART-00'.($index + 1), 'price' => $product[1], 'type' => $product[2], 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ([425000, 650000, 380000, 720000, 950000, 525000, 350000, 175000] as $index => $amount) {
                $issued = $index < 6;
                $paid = $index < 4;
                $issue = now()->startOfMonth()->subMonths(max(0, 3 - $index))->addDays(min(1, now()->day - 1));
                $id = DB::table('invoices')->insertGetId(['company_id' => $company, 'contact_id' => $clients[$index % 5], 'number' => $issued ? 'FAC-'.$issue->year.'-'.str_pad((string) ($index + 1), 5, '0', STR_PAD_LEFT) : null, 'status' => $paid ? 'paid' : ($issued ? 'issued' : ($index === 6 ? 'pending' : 'draft')), 'approval_round' => $index === 6 ? 1 : 0, 'approval_snapshot' => $index === 6 ? json_encode(InvoiceWorkflow::fallback()) : null, 'issue_date' => $issue->toDateString(), 'due_date' => $index === 4 ? now()->subDay()->toDateString() : now()->addDays(15)->toDateString(), 'customer_snapshot' => json_encode(['name' => ['Maison Baobab', 'Studio Ndar', 'Les Jardins de Saly', 'Teranga Distribution', 'Keur Design'][$index % 5], 'email' => 'contact'.($index % 5 + 1).'@example.test', 'address' => 'Dakar, Sénégal']), 'seller_snapshot' => json_encode(['name' => 'Atelier Teranga', 'address' => 'Mermoz, Dakar, Sénégal', 'ninea' => null, 'rccm' => null]), 'subtotal' => $amount, 'tax_basis_points' => 0, 'tax_amount' => 0, 'total' => $amount, 'notes' => 'Données fictives de démonstration.', 'created_by' => $user->id, 'approved_by' => $issued ? $user->id : null, 'created_at' => now(), 'updated_at' => now()]);
                DB::table('invoice_items')->insert(['invoice_id' => $id, 'description' => 'Prestation de communication', 'quantity' => 1, 'unit_price' => $amount, 'total' => $amount]);
                if ($paid) {
                    DB::table('payments')->insert(['invoice_id' => $id, 'company_id' => $company, 'amount' => $amount, 'paid_at' => $issue->toDateString(), 'method' => 'Virement', 'request_id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            foreach ([190000, 230000, 145000, 210000] as $index => $amount) {
                DB::table('expenses')->insert(['company_id' => $company, 'description' => 'Fonctionnement atelier — démonstration', 'category' => 'Fonctionnement', 'amount' => $amount, 'paid_at' => now()->startOfMonth()->subMonths(3 - $index)->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('audit_logs')->insert(['company_id' => $company, 'user_id' => $user->id, 'action' => 'Espace de démonstration créé', 'entity_type' => 'companies', 'entity_id' => $company, 'created_at' => now()]);
        });
    }
}
