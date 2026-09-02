package errs

import "fmt"

type Factory struct {
	prefix string
}

func NewErrorsFactory(prefix string) *Factory {
	return &Factory{
		prefix: prefix,
	}
}

func (f *Factory) ByErr(text string, err error) string {
	return f.make(text, err).Error()
}

func (f *Factory) ByText(text string) string {
	return fmt.Sprintf(
		"%s: %s",
		f.prefix,
		text,
	)
}

func (f *Factory) make(text string, err error) error {
	return fmt.Errorf(
		"%s: %s: %+v",
		f.prefix,
		text,
		err,
	)
}
