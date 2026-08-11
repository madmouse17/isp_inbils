<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE customers AS customer
            INNER JOIN users AS user
                ON user.company_id = customer.company_id
                AND user.email = customer.email
            INNER JOIN (
                SELECT company_id, email, MIN(id) AS customer_id
                FROM customers
                WHERE email IS NOT NULL
                GROUP BY company_id, email
                HAVING COUNT(*) = 1
            ) AS unique_customer
                ON unique_customer.customer_id = customer.id
            SET customer.user_id = user.id
            WHERE customer.user_id IS NULL
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('customers')->whereNotNull('user_id')->update(['user_id' => null]);
    }
};
