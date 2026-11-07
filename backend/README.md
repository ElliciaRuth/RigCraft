# RigCraft API (CodeIgniter 4)

The JSON API behind the RigCraft store and staff console. See the project's
main [README](../README.md) for setup, the endpoint list and the folder layout.

- Routes: `app/Config/Routes.php` (`php spark routes` lists them with filters)
- Business rules: `app/Config/RigCraft.php`
- Environment and database login: `.env`
- Schema: `php spark migrate`; sample data: `php spark db:seed RigCraftSeeder`
