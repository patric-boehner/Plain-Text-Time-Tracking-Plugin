# My Plugin Development Preferences

**A guide to how I like WordPress plugins built**

Hey! If you're building a WordPress plugin for me, this document explains how I think about things and what I prefer. These aren't arbitrary rules - they come from years of experience seeing what works and what causes headaches down the road.

## My Overall Philosophy

I believe in keeping things simple, explainable, and maintainable. I'd rather have a plugin that's straightforward to understand and modify than something that's "clever" but becomes a black box when you need to make changes. Every component should be small enough to understand at a glance and easy to adjust without breaking everything else.

When a client calls asking for a change at 2 PM (and they always do), I want to be able to figure out where that code lives, what it does, and how to modify it safely - without needing to untangle a web of dependencies.

### Trust WordPress Core

**Work with WordPress, not against it.** WordPress has spent years developing its features and APIs. Use it the way it's intended to be used.

This means:
- **Follow WordPress conventions** - Hooks, filters, template hierarchy, coding standards
- **Leverage built-in features** - Block styles, block patterns, template parts
- **Don't reinvent the wheel** - If WordPress has a solution, use it


The goal is **maintainable code that takes full advantage of WordPress's built-in way of doing things** while enforcing sensible limits that keep plugins clean and client-focused.

## Visual Design & User Experience

### Text Readability is Critical

All text must be easy to read with good contrast. Use color combinations that meet WCAG AA standards at minimum (WCAG AAA preferred). If someone has to squint or strain to read your content, you've failed.

Define your color palette in CSS variables and stick to it. Every color should have a purpose and a name. If you need variations, define them explicitly (primary, primary-dark, primary-light).

## Technical Preferences

### Keep It Vanilla

This is really important to me: **no frameworks**. I mean it.

- **CSS**: Plain CSS in separate files. No preprocessors, no Tailwind, no Bootstrap, no CSS-in-JS.
- **JavaScript**: Vanilla JavaScript when needed. No jQuery. React only when its a native WordPress commponent and can't easily be handled through Vanilla JS.

Why? Because in 5 years, vanilla code still works. Framework code often doesn't. Plus, anyone can jump in and understand vanilla code without learning a framework first.

Put your CSS and JavaScript in separate files (not inline) so browsers can cache them properly. Organize them logically by purpose, not by page.

## PHP & WordPress Plugin Development

### Follow WordPress Plugin Standards

Use the official WordPress Coding Standards for everything - naming conventions, formatting, documentation. This makes the code immediately recognizable to any WordPress developer.

### Procedural, Not Object-Oriented

Write procedural PHP code, not classes and objects. Why?

- **Simpler to understand**: Functions are straightforward and easy to follow
- **Easier to debug**: You can see exactly what's happening in what order
- **More maintainable**: Anyone can jump in and understand procedural code
- **WordPress-friendly**: Plugin functions work procedurally with hooks

### Prefix Everything

Use a consistent prefix for all functions to prevent conflicts:

Example: If your plugin is called "FSE Starter", use `fse_` as your prefix:
- Functions: `fse_setup()`, `fse_enqueue_scripts()`
- Filters: `fse_excerpt_length`
- Actions: `fse_modify_navigation_block`

**Never use generic names like `setup()` or `enqueue_scripts()` - always prefix.**

### Hook Everything Properly

- Use WordPress hooks (actions and filters) for everything
- Don't execute code directly - hook it to the appropriate action
- Name your hook callbacks clearly: `fse_setup()`, `fse_enqueue_scripts()`
- Document what hooks you're using and why
- Use appropriate priorities when order matters


**When to use filters vs letting WordPress handle it:**
- **Use filters when**: WordPress's default conflicts with your design system or creates accessibility issues
- **Let WordPress handle it when**: The default behavior works fine, even if you could "improve" it

Ask yourself: "Will this filter still make sense in 2 years when WordPress updates?" If the answer is uncertain, you might be fighting WordPress instead of working with it.

### Security First (Plugin Context)

- **Sanitize input**: Use `sanitize_text_field()`, `esc_attr()`, etc.
- **Escape output**: Use `esc_html()`, `esc_url()` everywhere in templates
- **Nonces for forms**: If you create custom forms (rare in FSE)
- **Check capabilities**: Always verify permissions for admin functions
- **Security headers**: Set proper X-Frame-Options headers

Example:
```php
// Good - escaped output
echo '<a href="' . esc_url( home_url() ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>';

// Bad - unescaped output
echo '<a href="' . home_url() . '">' . get_bloginfo( 'name' ) . '</a>';
```

### Keep Functions Small and Focused

Each function should do one thing well. If a function is more than 20-30 lines, consider breaking it up.

Good:
```php
function fse_add_footer_id( $block_content, $block ) {
    if ( isset( $block['attrs']['slug'] ) && $block['attrs']['slug'] === 'footer' ) {
        return str_replace( '<footer', '<footer id="footer"', $block_content );
    }
    return $block_content;
}
```

Bad:
```php
function fse_modify_everything( $block_content, $block ) {
    // 100 lines doing multiple different things
}
```

### Comment Your Code

- Explain WHY you're doing something, not WHAT you're doing
- Document function purposes with a brief description
- Add inline comments for complex logic
- Use clear variable names that reduce need for comments

Good:
```php
// Remove comments from admin bar since we've disabled comments site-wide
add_action( 'admin_bar_menu', 'fse_remove_admin_bar_comments', 999 );
```

Bad:
```php
// This removes comments
add_action( 'admin_bar_menu', 'fse_remove_admin_bar_comments', 999 );
```

## CSS Architecture & Organization

### CSS Strategy

- Write plain CSS - no preprocessors needed
- Keep selectors simple and specific
- Use CSS custom properties for repeated values
- Organize files by feature/component

## Development Practices

### Make It Debuggable

Write code as if you'll have to debug it at 2 AM while half-asleep (because you probably will):

- Clear, descriptive function names
- Helpful comments for non-obvious logic
- Good error messages
- Sufficient logging for filters/actions

## WordPress Admin UI Patterns

### Admin Notice Placement

**Critical Rule**: WordPress admin notices (success/error messages) must be placed correctly in the DOM or they will display in the wrong position.

**Correct Pattern**:
```php
<div class="wrap">
    <h1>Page Title</h1>

    <?php
    // Display notices AFTER the h1 title
    if ( isset( $_GET['message'] ) ) {
        echo '<div class="notice notice-success is-dismissible"><p>Success message</p></div>';
    }
    ?>

    <!-- Rest of page content -->
</div>
```

**Key Points**:
- Notices must come AFTER the `<h1>` page title (when H1 is at root level)
- Notices must be at the root level of `.wrap`, not nested in other containers
- **CRITICAL**: If your page has a header wrapper (e.g., `<div class="pltt-header">`), notices go AFTER the wrapper closes, not before or inside it
- WordPress will auto-relocate notices if they're in the wrong position, causing flashing/repositioning

**Why This Matters**:
WordPress's admin CSS expects notices to be siblings of the main page heading. If you place them before the heading or nested inside other containers, WordPress's JavaScript may try to relocate them, causing visual glitches and poor UX.

**Common Mistake**:
```php
<div class="wrap">
    <?php // DON'T put notices before the header ?>
    <div class="page-header">
        <h1>Page Title</h1>
    </div>
    <!-- Notice here won't display properly -->
</div>
```

**URL Cleanup**:
Always clean up notice query parameters from the URL after display to prevent notices persisting on page reload:
```javascript
if (window.location.search.includes('pltt_message')) {
    var url = new URL(window.location.href);
    url.searchParams.delete('pltt_message');
    window.history.replaceState({}, '', url.toString());
}
```

## Accessibility Requirements

### Non-Negotiable Standards

- **Semantic HTML**: Use proper elements (`<nav>`, `<article>`, `<aside>`)
- **Keyboard navigation**: Everything must be keyboard accessible
- **Screen reader text**: Add context for screen readers when needed
- **Focus states**: Clear, visible focus indicators
- **Color contrast**: WCAG AA minimum (AAA preferred)
- **Alt text**: Images must have appropriate alt attributes

### Screen Reader Context

Add context for screen readers when visual users have it but screen reader users don't:

```php
// Add post title to "Read more" links
$screen_reader_text = sprintf(
    '<span class="screen-reader-text">: %s</span>',
    esc_html( get_the_title() )
);
```

## Performance Considerations

### Asset Loading

- **Conditional loading**: Only load what's needed on each page
- **Editor assets**: Enqueue editor scripts/styles separately

### CSS Performance

- Keep selectors simple and shallow
- Avoid expensive operations (complex filters, shadows)
- Use CSS custom properties
- Let browsers cache compiled/minified CSS files
- Minimize specificity wars

### JavaScript (When Needed)

- Load scripts in footer when possible
- Use vanilla JavaScript - no libraries unless absolutely necessary
- Keep JavaScript minimal in Plugins
- Use WordPress's built-in libraries when available
- Always enqueue, never inline (except critical scripts)

## What Success Looks Like

When you build a Plugin for me, success means:

1. **Anyone can understand it** - Clear structure, good comments, logical organization
2. **It works with WordPress** - Uses core features, follows conventions, updates won't break it
3. **Editors find it intuitive** - Limited options, clear purpose
8. **It's accessible** - Keyboard navigation, screen readers, proper semantics
9. **It performs well** - Fast loading, efficient code, proper caching
10. **It's secure** - Escaped output, security headers, no vulnerabilities
11. **It works everywhere** - Desktop, tablet, phone - it all works
12. **Design system is enforced** - CSS control consistency

## Things That Drive Me Crazy

Just so we're clear, here are things I really don't want to see:

- Fighting WordPress instead of working with it
- Elaborate custom solutions when WordPress has a built-in feature
- Over-engineering the experience
- JavaScript frameworks when vanilla would work fine
- Hardcoded colors/spacing instead of using css varaible values
- Classes everywhere (procedural PHP in Plugins)
- All-or-nothing architectures that can't be changed
- Clever code that's hard to understand
- Cramped designs with no breathing room
- Exposing every possible setting to users

## Final Thoughts

I believe good plugin development is like good writing - it should be clear, purposeful, and as simple as possible while still doing the job. Every decision should have a reason, and that reason should be something better than "it's the trendy thing to do."

Build plugins that you'd want to maintain yourself two years from now. Build them like you'll have to explain them to someone else. Build them like the next developer might be you on a bad day.

**Work with WordPress, not against it.** WordPress Core have been thoughtfully designed. Use them as intended. Write procedural PHP. Keep CSS organized. Make it accessible. When WordPress has a solution, use it.

**Most importantly**: Build plugins that empower clients on their task. Give them sensable default, easy to understand options, no blank screens or empty results that leave them guessing how to use it or what to do next. Make the right thing the easy thing. And do it all using WordPress's built-in features whenever possible.

Remember: A maintainable plugin that works with WordPress beats a "perfect" custom solution every time. When WordPress updates, your plugin should benefit from those improvements, not break because you fought the system.

If you follow this document, you'll build plugins I can actually maintain, that take full advantage of WordPress's capabilities, and that will continue working as WordPress evolves - and that's what matters most.
