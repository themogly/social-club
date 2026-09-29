@php($club = \App\Support\OrganisationIdentity::current()['name'])
{{ __('Estos avisos siguen activos esta mañana.') }}
@foreach ($bySede as $sede => $sections)

{{ $sede }}
@foreach ($sections as $section)
{{ $section['heading'] }}
@foreach ($section['lines'] as $line)
{{ $line }}
@endforeach
@endforeach
@endforeach

—
{{ $club }}
