package contracts

import (
	"sconcur/internal/dto"
)

type StateContract interface {
	Next() *dto.Result
	Close()
}
