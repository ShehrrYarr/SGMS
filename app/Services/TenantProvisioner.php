<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Color;
use App\Models\ExpenseCategory;
use App\Models\PaymentAccount;
use App\Models\Size;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a ready-to-use shop: tenant, main branch with its default cash
 * drawer, the owner login and starter garment attributes.
 */
class TenantProvisioner
{
    public const DEFAULT_SIZES = [['S', 'Small'], ['M', 'Medium'], ['L', 'Large'], ['XL', 'Extra Large'], ['XXL', 'Double XL']];

    public const DEFAULT_COLORS = [['Black', '#111111'], ['White', '#FFFFFF'], ['Navy Blue', '#1F2A44'], ['Crimson Red', '#DC143C']];

    public const DEFAULT_EXPENSE_CATEGORIES = ['Rent', 'Salaries', 'Utilities', 'Transport', 'Marketing', 'Miscellaneous'];

    public function __construct(protected AccountService $accounts) {}

    /**
     * @param  array{name:string, slug?:string|null, plan?:string, trial_days?:int|null, phone?:string|null, address?:string|null,
     *               owner_name:string, owner_email:string, owner_password:string,
     *               branch_name?:string|null, opening_cash?:float|null, tax_rate?:float|null}  $data
     */
    public function create(array $data): Tenant
    {
        return DB::transaction(function () use ($data) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'status' => Tenant::STATUS_ACTIVE,
                'owner_email' => $data['owner_email'],
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'plan' => $data['plan'] ?? 'basic',
                'trial_ends_at' => ! empty($data['trial_days']) ? now()->addDays((int) $data['trial_days']) : null,
                'currency_symbol' => 'Rs.',
                'tax_rate' => $data['tax_rate'] ?? 0,
                'settings' => [
                    'receipt_width' => '80',
                    'receipt_footer' => 'Thank you for shopping with us! Exchange within 7 days with receipt.',
                    'allow_negative_balance' => false,
                ],
            ]);

            tenancy()->run($tenant, function () use ($tenant, $data) {
                $branch = $this->createBranch([
                    'name' => $data['branch_name'] ?? 'Main Store',
                    'code' => 'MAIN',
                    'phone' => $data['phone'] ?? null,
                    'address' => $data['address'] ?? null,
                    'default_cash_drawer_balance' => $data['opening_cash'] ?? 0,
                    'is_main' => true,
                ]);

                User::create([
                    'tenant_id' => $tenant->id,
                    'branch_id' => $branch->id,
                    'name' => $data['owner_name'],
                    'email' => $data['owner_email'],
                    'password' => $data['owner_password'],
                    'role' => User::ROLE_OWNER,
                    'status' => 'active',
                ]);

                $this->seedDefaults();
            });

            return $tenant;
        }, 3);
    }

    /** Create a branch together with its default cash drawer. Requires tenant context. */
    public function createBranch(array $data): Branch
    {
        return DB::transaction(function () use ($data) {
            $branch = Branch::create([
                'name' => $data['name'],
                'code' => Str::upper($data['code']),
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
                'default_cash_drawer_balance' => round_money($data['default_cash_drawer_balance'] ?? 0),
                'is_main' => (bool) ($data['is_main'] ?? false),
                'status' => 'active',
            ]);

            $drawer = PaymentAccount::create([
                'branch_id' => $branch->id,
                'account_name' => $branch->name.' Cash Drawer',
                'account_type' => PaymentAccount::TYPE_CASH,
                'opening_balance' => $branch->default_cash_drawer_balance,
                'current_balance' => 0,
                'is_default' => true,
                'status' => 'active',
            ]);

            if ((float) $branch->default_cash_drawer_balance > 0) {
                $this->accounts->credit($drawer->id, (float) $branch->default_cash_drawer_balance, 'opening', null, 'Opening cash float', $branch->id);
            }

            return $branch;
        });
    }

    /** Create a bank / wallet account with an opening balance. Requires tenant context. */
    public function createAccount(array $data): PaymentAccount
    {
        return DB::transaction(function () use ($data) {
            $account = PaymentAccount::create([
                'branch_id' => $data['branch_id'] ?? null,
                'account_name' => $data['account_name'],
                'account_type' => $data['account_type'],
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'opening_balance' => round_money($data['opening_balance'] ?? 0),
                'current_balance' => 0,
                'is_default' => false,
                'status' => 'active',
            ]);

            if ((float) $account->opening_balance > 0) {
                $this->accounts->credit($account->id, (float) $account->opening_balance, 'opening', null, 'Opening balance');
            }

            return $account;
        });
    }

    protected function seedDefaults(): void
    {
        foreach (self::DEFAULT_SIZES as $i => [$code, $name]) {
            Size::firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $i + 1]);
        }

        foreach (self::DEFAULT_COLORS as [$name, $hex]) {
            Color::firstOrCreate(['name' => $name], ['hex_code' => $hex]);
        }

        foreach (self::DEFAULT_EXPENSE_CATEGORIES as $name) {
            ExpenseCategory::firstOrCreate(['name' => $name]);
        }
    }

    public function uniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value) ?: 'shop';
        $slug = $base;
        $i = 2;

        while (Tenant::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()
            || in_array($slug, Tenant::RESERVED_SLUGS, true)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
