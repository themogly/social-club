@props(['name'])

{{--
    A field's validation error, ASSOCIATED with the field rather than merely printed near it (a11y audit).

    The application form showed one summary list at the top and nothing on the fields themselves, so a
    screen-reader user tabbing to the offending input was told it was fine. The input carries
    `aria-invalid` + `aria-describedby="{name}-error"`; this renders the message under that id.

    role="alert" so the message is announced when it appears, not only when the field is reached.
--}}
@error($name)
    <p id="{{ $name }}-error" role="alert" class="mt-1 text-xs font-medium text-error">{{ $message }}</p>
@enderror
