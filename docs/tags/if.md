Tag {if} [RU]
=============

Реализация оператора [if](http://docs.php.net/if) из PHP

```smarty
{if <expression>}
   {* ...code... *}
{elseif <expresion>}
   {* ...code... *}
{else}
   {* ...code... *}
{/if}
```

### {if}

```smarty
{if <expresion>}
    {*...some code...*}
{/if}
```

Код, расположенный в теге `{if}` будет выполнен/выведен если выражение *<expression>* возвращает значение приводимое к **TRUE**

### {elseif}

```smarty
{if <expresion1>}
    {*...some code...*}
{elseif <expresion2>}
    {*...some code...*}
{/if}
```

Код, расположенный после тега `{elseif}` будет выполнен/выведен, если выражение <expression1> вернуло значение приводимое к **FALSE**, <expression2> - приводимое к **TRUE**

### {else}

```smarty
{if <expresion>}
    {*...some code...*}
{else}
    {*...some code...*}
{/if}
```

Код, расположенный после тега `{else}` будет выполнен/выведен, если выражение <expression> вернуло значение приводимое к **FALSE**
В тестируемых выражениях могут быть использованы логические операторы , что позволяет обрабатывать сочетания нескольких условий.