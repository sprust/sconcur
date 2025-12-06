<?php

namespace SConcur\Features;

enum MethodEnum: int
{
    case Read = 1;
    case Sleep = 2;
    case Mongodb = 3;
}
