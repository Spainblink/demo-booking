---
paths:
  - '**/*.php'
---

# Php

## Follow PSR and run Pint
Follow PSR-1/PSR-12 for all PHP code. After modifying PHP files, run vendor/bin/pint --dirty --format agent. Match existing project conventions and use descriptive names.

## PHPDoc на русском с @param, @return и @throws
Пишите PHPDoc на русском языке. Документируйте public/protected методы, свойства и сложную private-логику блоками PHPDoc, а не inline-комментариями. В каждом PHPDoc обязательно указывайте `@param` для всех параметров, `@return` и `@throws` (если метод может выбросить исключение). Для структурированных массивов используйте array shapes.
