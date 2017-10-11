# Contextual aliases

This module allows to split Drupal's url aliases into different contexts that allow to use the *same* alias for
*different* source paths. The most prominent use case is a setup with different domains, where the same path is
supposed to point to a different node, depending on the current domain.

```
http://a.com/imprint -> /node/1
http://b.com/imprint -> /node/2
http://c.com/imprint -> /node/3
```

**Disclaimer:** This is an advanced module that requires coding and knowledge about cache configuration.

## How it works

When saving a new alias, the module will search for services tagged with `alias_context_resolver` that implement the
`AliasContextResolverInterface`, and use them to determine the context for a given path.

```
/node/1 -> a
/node/2 -> b
/node/3 -> NULL
```

It will store this path and use it for resolving paths from aliases and vice versa. There it will consult the context
resolver again to obtain the *current* context (e.g. the domain the request was sent to). If the result matches the
stored context for this alias, it will behave normal. If not, aliases will prefixed (both ways) with their context.
Non-contextual aliases remain functional, but will be picked with a lower priority.

### Current context: none
```
/node/1 -> /a/imprint
/node/2 -> /b/imprint
/node/3 -> /imprint

/imprint -> /node/3
```

### Current context: 'a'
```
/node/1 -> /imprint
/node/2 -> /b/imprint
/node/3 -> /imprint

/imprint -> /node/1
```

### Current context: 'b'
```
current context: b

/node/1 -> /a/imprint
/node/2 -> /imprint
/node/3 -> /imprint

/imprint -> /node/2
```

## How to use it
The module requires you to implement your own context resolvers and add them as services tagged with
`alias_context_resolver` and (more importantly) configure caching in a way it also respects the same contextual
information. Otherwise you will have a very bad time watching your page cache serving random pages.

