## Matra ERP

ERP stands for Enterprise Resource Planning, and it's a type of software that helps businesses manage core processes. ERP systems can automate and integrate many business functions, such as accounting, supply chain, and human resources.

# Running Project

-   git pull origin main
-   composer install
-   php artisan migrate
-   composer run dev

# Run Queue/Socket

1. Queue

```
 php artisan queue:work

```

2. Reverb

```
 php artisan reverb:start --debug
```

# Usefull Cmds

1. Import Database mysql

```
mysql -u mantra1 -p mantraerp_prod1 < matraerp.sql

```

# Check Services

-   sudo ps aux | grep artisan
-   sudo supervisorctl restart all
