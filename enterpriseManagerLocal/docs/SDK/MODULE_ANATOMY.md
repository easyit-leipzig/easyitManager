# Modulaufbau

Ein SDK-Modul liegt unter `modules/<slug>/`.

```text
modules/inventory/
├── module.json
├── bootstrap.php
├── src/
│   ├── Providers/
│   ├── Events/
│   ├── Listeners/
│   ├── Jobs/
│   ├── Console/
│   ├── Models/
│   ├── Http/
│   └── Lifecycle/
├── resources/
│   ├── views/
│   └── themes/
├── config/
├── forms/
├── database/migrations/
├── tests/
├── README.md
└── DEVELOPMENT.md
```

`module.json` ist die maschinenlesbare Moduldefinition. Entry-Klasse, Capabilities,
Routen und Lifecycle-Klassen werden durch `module:validate` geprüft.
