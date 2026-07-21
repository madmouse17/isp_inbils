$tables = @(
  'companies','users','roles','permissions','settings','units','categories','products','locations','stocks','customers','customer_addresses','service_subscriptions','service_packages','ticket_categories','sla_tiers','bandwidth_profiles','speed_profiles','network_assets'
)
foreach ($t in $tables) {
  $count = & php artisan tinker --execute "echo DB::table('$t')->count();" 2>$null
  Write-Output "$t=$count"
}
