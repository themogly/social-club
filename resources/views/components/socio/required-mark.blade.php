{{-- The SIGHTED half of "required" (WCAG 3.3.2, prompt 155). The programmatic half is the input's own
     `required` attribute, which assistive tech already announces — so this asterisk is aria-hidden, to mark the
     field for a sighted applicant without doubling the announcement for a screen-reader user. --}}
<span aria-hidden="true" class="text-error">*</span>
